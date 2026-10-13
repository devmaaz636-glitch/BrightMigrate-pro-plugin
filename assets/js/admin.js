/**
 * BrightMigrate Pro admin screens.
 */

(function () {
	'use strict';

	let onStop = function () {};
	let announced = -1;

	/**
	 * Localized settings printed by PHP.
	 *
	 * @return {Object} Admin settings printed by PHP.
	 */
	function config() {
		return (
			window.brightmigrateAdmin || {
				ajaxUrl: '',
				nonce: '',
				i18n: {},
			}
		);
	}

	/**
	 * Text from the localized strings.
	 *
	 * @param {string} key      String key.
	 * @param {string} fallback Text used when the key is missing.
	 * @return {string} Localized text, or the fallback.
	 */
	function text(key, fallback) {
		const strings = config().i18n || {};
		return strings[key] || fallback;
	}

	/**
	 * The shared progress window.
	 *
	 * @return {HTMLElement|null} Modal element.
	 */
	function modal() {
		return document.querySelector('[data-bmx-modal]');
	}

	/**
	 * Update the polite live region when the percent moves.
	 *
	 * @param {string} message Status text.
	 * @param {number} percent Percent from 0 to 100.
	 * @return {void}
	 */
	function announce(message, percent) {
		const region = document.getElementById('bmx-live-region');
		if (!region) {
			return;
		}
		if (Math.abs(percent - announced) < 5 && region.textContent) {
			return;
		}
		announced = percent;
		region.textContent = message + ' ' + percent + '%';
	}

	/**
	 * Show the percentage, bar, and status line.
	 *
	 * @param {Object} state Title, message, and percent.
	 * @return {void}
	 */
	function showProgress(state) {
		const box = modal();
		if (!box) {
			return;
		}
		const percent = Math.max(
			0,
			Math.min(100, Math.round(Number(state.percent) || 0))
		);
		const title = box.querySelector('[data-bmx-modal-title]');
		const message = box.querySelector('[data-bmx-modal-message]');
		const figure = box.querySelector('[data-bmx-percent]');
		const bar = box.querySelector('[data-bmx-progress]');
		const fill = box.querySelector('[data-bmx-progress-bar]');
		if (title && state.title) {
			title.textContent = state.title;
		}
		if (message) {
			message.textContent = state.message || '';
		}
		if (figure) {
			figure.textContent = percent + '%';
		}
		if (bar) {
			bar.setAttribute('aria-valuenow', String(percent));
		}
		if (fill) {
			fill.style.width = percent + '%';
		}
		box.hidden = false;
		announce(state.message || state.title || '', percent);
	}

	/**
	 * Show or hide the modal buttons.
	 *
	 * @param {Object} flags Which buttons stay visible.
	 * @return {void}
	 */
	function showButtons(flags) {
		const box = modal();
		if (!box) {
			return;
		}
		['stop', 'save', 'copy', 'close'].forEach(function (name) {
			const button = box.querySelector('[data-bmx-' + name + ']');
			if (button) {
				button.hidden = !flags[name];
				button.disabled = false;
			}
		});
	}

	/**
	 * Hide the progress window.
	 *
	 * @return {void}
	 */
	function hideProgress() {
		const box = modal();
		if (box) {
			box.hidden = true;
		}
		announced = -1;
	}

	/**
	 * Post one admin-ajax step and return its data.
	 *
	 * @param {Object} fields Form fields. A Blob is sent as the chunk.
	 * @return {Promise<Object>} Successful response data.
	 */
	function post(fields) {
		const body = new window.FormData();
		body.append('action', 'brightmigrate_export');
		body.append('nonce', config().nonce);
		Object.keys(fields).forEach(function (key) {
			const value = fields[key];
			if (Array.isArray(value)) {
				value.forEach(function (item, index) {
					if (item && 'object' === typeof item) {
						body.append(
							key + '[' + index + '][from]',
							item.from || ''
						);
						body.append(key + '[' + index + '][to]', item.to || '');
					} else {
						body.append(key + '[]', item);
					}
				});
				return;
			}
			if (value instanceof window.Blob) {
				body.append(key, value, 'chunk.bin');
				return;
			}
			body.append(
				key,
				null === value || 'undefined' === typeof value
					? ''
					: String(value)
			);
		});

		return window
			.fetch(config().ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			})
			.then(function (response) {
				return response.json().then(function (payload) {
					if (!payload || !payload.success) {
						const message =
							payload && payload.data && payload.data.message
								? payload.data.message
								: text(
										'failed',
										'The export could not continue.'
									);
						throw new Error(message);
					}
					return payload.data;
				});
			});
	}

	/**
	 * Repeat a step up to three times when the connection fails.
	 *
	 * @param {Object}   fields     Form fields.
	 * @param {Function} shouldStop Returns true when the user stopped the job.
	 * @param {number}   tryNumber  Attempt count, starting at 1.
	 * @return {Promise<Object>} Successful response data.
	 */
	function postRetry(fields, shouldStop, tryNumber) {
		const attempt = tryNumber || 1;
		return post(fields).catch(function (error) {
			if (attempt >= 3 || (shouldStop && shouldStop())) {
				throw error;
			}
			return new Promise(function (resolve) {
				window.setTimeout(resolve, attempt * 1000);
			}).then(function () {
				return postRetry(fields, shouldStop, attempt + 1);
			});
		});
	}

	/**
	 * Bytes in one upload step. This is not a limit on the whole site.
	 *
	 * @return {number} Chunk size in bytes.
	 */
	function chunkBytes() {
		const value = Number(config().chunkBytes);
		if (value >= 262144) {
			return value;
		}
		return 2 * 1024 * 1024;
	}

	/**
	 * Save a file and update the percentage from bytes received.
	 *
	 * @param {string} url  Download address.
	 * @param {string} name File name.
	 * @return {Promise<void>}
	 */
	function downloadFile(url, name) {
		const controller = new window.AbortController();
		onStop = function () {
			controller.abort();
		};
		showButtons({ stop: true });
		showProgress({
			title: text('downloading', 'Downloading'),
			message: text('downloading', 'Downloading'),
			percent: 0,
		});

		let writable = null;
		const extension =
			name && name.toLowerCase().endsWith('.wpress') ? '.wpress' : '.bmx';
		const openPicker = window.showSaveFilePicker
			? window.showSaveFilePicker({
					suggestedName: name,
					types: [
						{
							description:
								'.wpress' === extension
									? 'WPRESS archive'
									: 'BrightMigrate archive',
							accept: {
								'application/octet-stream': [extension],
							},
						},
					],
				})
			: Promise.resolve(null);

		return openPicker
			.then(function (handle) {
				if (!handle) {
					return null;
				}
				return handle.createWritable();
			})
			.then(function (stream) {
				writable = stream;
				return window.fetch(url, {
					credentials: 'same-origin',
					signal: controller.signal,
				});
			})
			.then(function (response) {
				if (!response.ok || !response.body) {
					throw new Error(
						text('downloadFailed', 'The download could not finish.')
					);
				}
				const total =
					Number(response.headers.get('Content-Length')) || 0;
				const reader = response.body.getReader();
				const chunks = [];
				let received = 0;

				function read() {
					return reader.read().then(function (result) {
						if (result.done) {
							return chunks;
						}
						received += result.value.length;
						if (writable) {
							return writable
								.write(result.value)
								.then(function () {
									const percent = total
										? Math.min(
												99,
												Math.round(
													(received / total) * 100
												)
											)
										: 0;
									showProgress({
										title: text(
											'downloading',
											'Downloading'
										),
										message: text(
											'downloading',
											'Downloading'
										),
										percent,
									});
									return read();
								});
						}
						chunks.push(result.value);
						showProgress({
							title: text('downloading', 'Downloading'),
							message: text('downloading', 'Downloading'),
							percent: total
								? Math.min(
										99,
										Math.round((received / total) * 100)
									)
								: 0,
						});
						return read();
					});
				}

				return read().then(function () {
					if (writable) {
						return writable.close();
					}
					const blob = new window.Blob(chunks);
					const objectUrl = window.URL.createObjectURL(blob);
					const link = document.createElement('a');
					link.href = objectUrl;
					link.download = name;
					document.body.appendChild(link);
					link.click();
					link.remove();
					window.URL.revokeObjectURL(objectUrl);
					return null;
				});
			})
			.then(function () {
				showButtons({ close: true });
				showProgress({
					title: text('downloadDone', 'Download complete'),
					message: text('downloadDone', 'Download complete'),
					percent: 100,
				});
			})
			.catch(function (error) {
				if (writable && writable.abort) {
					writable.abort();
				}
				const cancelled =
					error &&
					(error.name === 'AbortError' ||
						error.name === 'NotAllowedError');
				let message = text(
					'downloadFailed',
					'The download could not finish.'
				);
				if (cancelled) {
					message = text('cancelled', 'Cancelled.');
				} else if (error && error.message) {
					message = error.message;
				}
				showButtons({ close: true });
				showProgress({
					title: message,
					message,
					percent: 0,
				});
			});
	}

	/**
	 * Bind the full-site export form.
	 *
	 * @return {void}
	 */
	function bindExport() {
		const root = document.querySelector('[data-bmx-export]');
		if (!root) {
			return;
		}
		const pairs = root.querySelector('[data-bmx-pairs]');
		const add = root.querySelector('[data-bmx-add-pair]');
		const start = root.querySelector('[data-bmx-export-start]');
		const box = modal();
		const save = box ? box.querySelector('[data-bmx-save]') : null;
		const copy = box ? box.querySelector('[data-bmx-copy]') : null;
		let running = false;
		let stopRequested = false;
		let readyUrl = '';
		let readyName = '';

		if (add && pairs) {
			add.addEventListener('click', function () {
				if (pairs.querySelectorAll('.bmx-replace-row').length >= 10) {
					return;
				}
				const row = pairs.querySelector('.bmx-replace-row');
				if (!row) {
					return;
				}
				const next = row.cloneNode(true);
				next.querySelectorAll('input').forEach(function (input) {
					input.value = '';
				});
				pairs.appendChild(next);
			});
		}

		function collectedPairs() {
			const rows = [];
			root.querySelectorAll('.bmx-replace-row').forEach(function (row) {
				const from = row.querySelector('[data-bmx-from]');
				const to = row.querySelector('[data-bmx-to]');
				rows.push({
					from: from ? from.value : '',
					to: to ? to.value : '',
				});
			});
			return rows;
		}

		function collectedExcludes() {
			const keys = [];
			root.querySelectorAll('[data-bmx-exclude]:checked').forEach(
				function (boxItem) {
					keys.push(boxItem.value);
				}
			);
			return keys;
		}

		function collectedScope() {
			const picked = root.querySelector('[data-bmx-scope]:checked');
			return picked ? picked.value : 'full';
		}

		function passwordValue() {
			const field = root.querySelector('[data-bmx-password]');
			return field ? field.value : '';
		}

		function applyState(state) {
			const line = state.message || text('working', 'Working…');
			const done = 'done' === state.status;
			showProgress({
				title: done
					? text('exportDone', 'Export complete')
					: text('exporting', 'Exporting'),
				message: line,
				percent: done ? 100 : state.progress,
			});
			if (done && state.download) {
				readyUrl = state.download;
				readyName = state.file || 'brightmigrate.wpress';
				showButtons({ save: true, copy: true, close: true });
			}
		}

		if (copy) {
			copy.addEventListener('click', function () {
				const done = function () {
					announce(text('copied', 'Download link copied.'), 100);
				};
				if (
					readyUrl &&
					window.navigator.clipboard &&
					window.navigator.clipboard.writeText
				) {
					window.navigator.clipboard.writeText(readyUrl).then(done);
				}
			});
		}

		if (save) {
			save.addEventListener('click', function () {
				if (readyUrl) {
					downloadFile(readyUrl, readyName);
				}
			});
		}

		if (!start) {
			return;
		}

		start.addEventListener('click', function () {
			if (running) {
				return;
			}
			running = true;
			stopRequested = false;
			readyUrl = '';
			start.disabled = true;
			showButtons({ stop: true });
			showProgress({
				title: text('exporting', 'Exporting'),
				message: text('working', 'Working…'),
				percent: 1,
			});
			onStop = function () {
				stopRequested = true;
				const stop = box ? box.querySelector('[data-bmx-stop]') : null;
				if (stop) {
					stop.disabled = true;
				}
			};

			const fields = {
				excludes: collectedExcludes(),
				pairs: collectedPairs(),
				password: passwordValue(),
				scope: collectedScope(),
			};
			const stopped = function () {
				return stopRequested;
			};

			postRetry({ step: 'preflight' }, stopped)
				.then(function (check) {
					if (check && 'blocked' === check.status) {
						showButtons({ close: true });
						showProgress({
							title: text(
								'failed',
								'The export could not continue.'
							),
							message: check.message || '',
							percent: 0,
						});
						return null;
					}
					if (check && check.message) {
						showProgress({
							title: text('exporting', 'Exporting'),
							message: check.message,
							percent: 1,
						});
					}
					return postRetry(
						Object.assign({ step: 'start' }, fields),
						stopped
					);
				})
				.then(function (started) {
					if (!started || !started.id) {
						return null;
					}
					let state = started;
					applyState(state);

					function next() {
						if (stopRequested) {
							return post({
								step: 'cancel',
								id: started.id,
							}).then(function () {
								showButtons({ close: true });
								showProgress({
									title: text('stopped', 'Export stopped.'),
									message: text('stopped', 'Export stopped.'),
									percent: 0,
								});
							});
						}
						if (!state || 'running' !== state.status) {
							return Promise.resolve();
						}
						return postRetry(
							{
								step: 'tick',
								id: started.id,
								password: fields.password,
							},
							stopped
						).then(function (updated) {
							state = updated;
							applyState(state);
							if ('running' === state.status && !stopRequested) {
								return next();
							}
							if (stopRequested && 'done' !== state.status) {
								return post({
									step: 'cancel',
									id: started.id,
								});
							}
							return Promise.resolve();
						});
					}

					return next();
				})
				.catch(function (error) {
					const line =
						error && error.message
							? error.message
							: text('failed', 'The export could not continue.');
					showButtons({ close: true });
					showProgress({
						title: text('failed', 'The export could not continue.'),
						message: line,
						percent: 0,
					});
				})
				.then(function () {
					running = false;
					start.disabled = false;
				});
		});
	}

	/**
	 * Bind the import drop zone.
	 *
	 * @return {void}
	 */
	function bindImport() {
		const drop = document.querySelector('[data-bmx-drop]');
		if (!drop) {
			return;
		}
		const input = drop.querySelector('[data-bmx-file]');
		const pick = drop.querySelector('[data-bmx-pick]');
		const summary = document.querySelector('[data-bmx-summary]');
		let stopRequested = false;

		function passwordValue() {
			const field = drop.querySelector('[data-bmx-upload-password]');
			return field ? field.value : '';
		}

		function showSummary(data) {
			if (!summary) {
				return;
			}
			summary.hidden = false;
			[
				'file',
				'type',
				'source_url',
				'files',
				'database_parts',
				'created_at',
			].forEach(function (key) {
				const cell = summary.querySelector(
					'[data-bmx-sum="' + key + '"]'
				);
				if (cell) {
					cell.textContent =
						null === data[key] || 'undefined' === typeof data[key]
							? ''
							: String(data[key]);
				}
			});
		}

		function upload(file) {
			const lower = (file.name || '').toLowerCase();
			if (!lower.endsWith('.wpress') && !lower.endsWith('.bmx')) {
				showButtons({ close: true });
				showProgress({
					title: text(
						'uploadFailed',
						'The upload could not continue.'
					),
					message: text('wrongType', 'Choose a .wpress file.'),
					percent: 0,
				});
				return Promise.resolve();
			}
			const slice = chunkBytes();
			const total = Math.max(1, Math.ceil(file.size / slice));
			let uploadId = '';
			let index = 0;
			stopRequested = false;
			showButtons({ stop: true });
			showProgress({
				title: text('importing', 'Importing'),
				message: text('importing', 'Importing'),
				percent: 0,
			});
			onStop = function () {
				stopRequested = true;
			};

			function step() {
				if (stopRequested) {
					showButtons({ close: true });
					showProgress({
						title: text('cancelled', 'Cancelled.'),
						message: text('cancelled', 'Cancelled.'),
						percent: 0,
					});
					return Promise.resolve();
				}
				const startAt = index * slice;
				const blob = file.slice(
					startAt,
					Math.min(file.size, startAt + slice)
				);
				const percent = Math.min(99, Math.round((index / total) * 100));
				showProgress({
					title: text('importing', 'Importing'),
					message: text('importing', 'Importing'),
					percent,
				});
				return postRetry(
					{
						step: 'upload',
						id: uploadId,
						index: String(index),
						total: String(total),
						password: passwordValue(),
						chunk: blob,
					},
					function () {
						return stopRequested;
					}
				).then(function (data) {
					uploadId = data.upload_id || uploadId;
					showProgress({
						title:
							'ready' === data.status
								? text('importDone', 'Import complete')
								: text('importing', 'Importing'),
						message: data.message || text('importing', 'Importing'),
						percent: 'ready' === data.status ? 100 : data.progress,
					});
					if ('ready' === data.status) {
						showButtons({ close: true });
						showSummary(data);
						return data;
					}
					index += 1;
					if (index < total) {
						return step();
					}
					return data;
				});
			}

			return step().catch(function (error) {
				const line =
					error && error.message
						? error.message
						: text(
								'uploadFailed',
								'The upload could not continue.'
							);
				showButtons({ close: true });
				showProgress({
					title: text(
						'uploadFailed',
						'The upload could not continue.'
					),
					message: line,
					percent: 0,
				});
			});
		}

		if (pick && input) {
			pick.addEventListener('click', function () {
				input.click();
			});
		}

		if (input) {
			input.addEventListener('change', function () {
				if (input.files && input.files[0]) {
					upload(input.files[0]);
				}
			});
		}

		['dragenter', 'dragover'].forEach(function (name) {
			drop.addEventListener(name, function (event) {
				event.preventDefault();
				drop.classList.add('is-over');
			});
		});
		['dragleave', 'drop'].forEach(function (name) {
			drop.addEventListener(name, function (event) {
				event.preventDefault();
				drop.classList.remove('is-over');
			});
		});
		drop.addEventListener('drop', function (event) {
			const files = event.dataTransfer && event.dataTransfer.files;
			if (files && files[0]) {
				upload(files[0]);
			}
		});
	}

	/**
	 * Close every backup action menu.
	 *
	 * @return {void}
	 */
	function closeBackupMenus() {
		document.querySelectorAll('.bmx-menu').forEach(function (menu) {
			menu.hidden = true;
		});
		document.querySelectorAll('.bmx-kebab').forEach(function (button) {
			button.setAttribute('aria-expanded', 'false');
		});
	}

	/**
	 * Three-dot menus for download, restore, and delete.
	 *
	 * @return {void}
	 */
	function bindBackupMenus() {
		const confirmBox = document.querySelector('[data-bmx-confirm]');
		if (!confirmBox) {
			return;
		}
		const title = confirmBox.querySelector('[data-bmx-confirm-title]');
		const file = confirmBox.querySelector('[data-bmx-confirm-file]');
		const message = confirmBox.querySelector('[data-bmx-confirm-message]');
		const passwordWrap = confirmBox.querySelector(
			'[data-bmx-confirm-password-wrap]'
		);
		const password = confirmBox.querySelector(
			'[data-bmx-confirm-password]'
		);
		const cancel = confirmBox.querySelector('[data-bmx-confirm-cancel]');
		const ok = confirmBox.querySelector('[data-bmx-confirm-ok]');
		let pending = null;

		function hideConfirm() {
			confirmBox.hidden = true;
			pending = null;
			if (password) {
				password.value = '';
			}
		}

		function openConfirm(action, name) {
			pending = {
				action,
				name,
			};
			if (title) {
				title.textContent =
					'delete' === action
						? text('deleteTitle', 'Delete backup')
						: text('restoreTitle', 'Restore backup');
			}
			if (file) {
				file.textContent = name;
			}
			if (message) {
				message.textContent =
					'delete' === action
						? text(
								'deleteBody',
								'Delete this backup from the server? The file cannot be recovered.'
							)
						: text(
								'restoreBody',
								'This checks the backup and shows its details. The live site is not replaced yet.'
							);
			}
			if (passwordWrap) {
				passwordWrap.hidden = 'restore' !== action;
			}
			if (ok) {
				ok.textContent =
					'delete' === action
						? text('deleteAction', 'Delete')
						: text('restoreAction', 'Check backup');
				ok.classList.toggle('is-danger', 'delete' === action);
			}
			confirmBox.hidden = false;
			if (ok) {
				ok.focus();
			}
		}

		document.querySelectorAll('.bmx-kebab').forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.stopPropagation();
				const menu = button.parentElement
					? button.parentElement.querySelector('.bmx-menu')
					: null;
				const wasClosed = !menu || menu.hidden;
				closeBackupMenus();
				if (menu && wasClosed) {
					const rect = button.getBoundingClientRect();
					menu.hidden = false;
					menu.style.position = 'fixed';
					menu.style.top = rect.bottom + 6 + 'px';
					menu.style.left =
						Math.max(8, rect.right - menu.offsetWidth) + 'px';
					button.setAttribute('aria-expanded', 'true');
				}
			});
		});

		document.querySelectorAll('.bmx-menu button').forEach(function (item) {
			item.addEventListener('click', function (event) {
				event.stopPropagation();
				const cell = item.closest('.bmx-row-actions');
				const kebab = cell ? cell.querySelector('.bmx-kebab') : null;
				const name = kebab
					? kebab.getAttribute('data-bmx-file-name')
					: '';
				const url = kebab
					? kebab.getAttribute('data-bmx-file-url')
					: '';
				const action = item.getAttribute('data-bmx-act');
				closeBackupMenus();
				if ('download' === action && url) {
					downloadFile(url, name || 'brightmigrate.wpress');
					return;
				}
				if (name && ('delete' === action || 'restore' === action)) {
					openConfirm(action, name);
				}
			});
		});

		document.addEventListener('click', closeBackupMenus);
		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				closeBackupMenus();
				hideConfirm();
			}
		});

		if (cancel) {
			cancel.addEventListener('click', hideConfirm);
		}

		if (ok) {
			ok.addEventListener('click', function () {
				if (!pending) {
					return;
				}
				const action = pending.action;
				const name = pending.name;
				const secret = password ? password.value : '';
				hideConfirm();
				if ('delete' === action) {
					post({
						step: 'delete',
						file: name,
					})
						.then(function () {
							const row = document.querySelector(
								'.bmx-kebab[data-bmx-file-name="' +
									name.replace(/"/g, '') +
									'"]'
							);
							const tr = row ? row.closest('tr') : null;
							if (tr) {
								tr.remove();
							}
							const body =
								document.querySelector('.bmx-backups tbody');
							if (body && !body.querySelector('tr')) {
								const empty = document.createElement('tr');
								const cell = document.createElement('td');
								cell.colSpan = 6;
								cell.textContent = text(
									'none',
									'No archives yet.'
								);
								empty.appendChild(cell);
								body.appendChild(empty);
							}
						})
						.catch(function (error) {
							showButtons({ close: true });
							showProgress({
								title: text('deleteTitle', 'Delete backup'),
								message:
									error && error.message
										? error.message
										: text(
												'failed',
												'The export could not continue.'
											),
								percent: 0,
							});
						});
					return;
				}
				showButtons({ stop: false, close: false });
				showProgress({
					title: text('checking', 'Checking the backup'),
					message: text('checking', 'Checking the backup'),
					percent: 15,
				});
				post({
					step: 'restore',
					file: name,
					password: secret,
				})
					.then(function (data) {
						showButtons({ close: true });
						showProgress({
							title: text('restoreTitle', 'Restore backup'),
							message: data.message || '',
							percent: 100,
						});
					})
					.catch(function (error) {
						showButtons({ close: true });
						showProgress({
							title: text('restoreTitle', 'Restore backup'),
							message:
								error && error.message
									? error.message
									: text(
											'failed',
											'The export could not continue.'
										),
							percent: 0,
						});
					});
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		const region = document.getElementById('bmx-live-region');
		const box = modal();
		const stop = box ? box.querySelector('[data-bmx-stop]') : null;
		const close = box ? box.querySelector('[data-bmx-close]') : null;
		if (region) {
			region.setAttribute('data-bmx-ready', 'true');
		}
		if (stop) {
			stop.addEventListener('click', function () {
				onStop();
			});
		}
		if (close) {
			close.addEventListener('click', hideProgress);
		}
		bindExport();
		bindImport();
		bindBackupMenus();
	});
})();
