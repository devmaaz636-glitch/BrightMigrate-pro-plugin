/**
 * BrightMigrate Pro admin screens.
 */

(function () {
	'use strict';

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
				body.append(key, value, 'chunk.bmx');
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
	 * Update the polite live region.
	 *
	 * @param {string} message Status text.
	 * @return {void}
	 */
	function announce(message) {
		const region = document.getElementById('bmx-live-region');
		if (region) {
			region.textContent = message;
		}
	}

	/**
	 * Set a progress bar element.
	 *
	 * @param {HTMLElement|null} bar   Track element.
	 * @param {HTMLElement|null} fill  Fill element.
	 * @param {number}           value Percent from 0 to 100.
	 * @return {void}
	 */
	function setProgress(bar, fill, value) {
		const percent = Math.max(0, Math.min(100, Number(value) || 0));
		if (bar) {
			bar.setAttribute('aria-valuenow', String(percent));
		}
		if (fill) {
			fill.style.width = percent + '%';
		}
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
		const modal = document.querySelector('[data-bmx-modal]');
		const message = modal
			? modal.querySelector('[data-bmx-modal-message]')
			: null;
		const bar = modal ? modal.querySelector('[data-bmx-progress]') : null;
		const fill = modal
			? modal.querySelector('[data-bmx-progress-bar]')
			: null;
		const stop = modal ? modal.querySelector('[data-bmx-stop]') : null;
		const download = modal
			? modal.querySelector('[data-bmx-download]')
			: null;
		const copy = modal ? modal.querySelector('[data-bmx-copy]') : null;
		let running = false;
		let stopRequested = false;

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
				function (box) {
					keys.push(box.value);
				}
			);
			return keys;
		}

		function passwordValue() {
			const field = root.querySelector('[data-bmx-password]');
			return field ? field.value : '';
		}

		function showModal(open) {
			if (!modal) {
				return;
			}
			if (open) {
				modal.hidden = false;
				if (stop) {
					stop.hidden = false;
					stop.focus();
				}
			} else {
				modal.hidden = true;
			}
		}

		function applyState(state) {
			const line = state.message || text('working', 'Working…');
			if (message) {
				message.textContent = line;
			}
			announce(line);
			setProgress(bar, fill, state.progress);
			if ('done' === state.status && state.download && download) {
				download.hidden = false;
				download.href = state.download;
				download.focus();
				if (stop) {
					stop.hidden = true;
				}
				if (copy) {
					copy.hidden = false;
				}
			}
		}

		if (stop) {
			stop.addEventListener('click', function () {
				stopRequested = true;
				stop.disabled = true;
			});
		}

		if (copy && download) {
			copy.addEventListener('click', function () {
				const href = download.href;
				const done = function () {
					announce(text('copied', 'Download link copied.'));
				};
				if (
					window.navigator.clipboard &&
					window.navigator.clipboard.writeText
				) {
					window.navigator.clipboard.writeText(href).then(done);
					return;
				}
				done();
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
			start.disabled = true;
			if (download) {
				download.hidden = true;
				download.removeAttribute('href');
			}
			if (copy) {
				copy.hidden = true;
			}
			if (stop) {
				stop.disabled = false;
			}
			setProgress(bar, fill, 1);
			showModal(true);

			const fields = {
				excludes: collectedExcludes(),
				pairs: collectedPairs(),
				password: passwordValue(),
			};

			post(Object.assign({ step: 'start' }, fields))
				.then(function (started) {
					let state = started;
					applyState(state);

					function next() {
						if (stopRequested) {
							return post({
								step: 'cancel',
								id: started.id,
							}).then(function () {
								const line = text('stopped', 'Export stopped.');
								if (message) {
									message.textContent = line;
								}
								announce(line);
								if (stop) {
									stop.hidden = true;
								}
							});
						}
						if (!state || 'running' !== state.status) {
							return Promise.resolve();
						}
						return post({
							step: 'tick',
							id: started.id,
							password: fields.password,
						}).then(function (updated) {
							state = updated;
							applyState(state);
							if ('running' === state.status && !stopRequested) {
								return next();
							}
							if (stopRequested && 'done' !== state.status) {
								return post({ step: 'cancel', id: started.id });
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
					if (message) {
						message.textContent = line;
					}
					announce(line);
					showModal(true);
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
		const bar = drop.querySelector('[data-bmx-upload-progress]');
		const fill = drop.querySelector('[data-bmx-upload-bar]');
		const message = drop.querySelector('[data-bmx-upload-message]');
		const summary = document.querySelector('[data-bmx-summary]');

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
			const slice = 2 * 1024 * 1024;
			const total = Math.max(1, Math.ceil(file.size / slice));
			let uploadId = '';
			let index = 0;
			if (message) {
				message.textContent = text('working', 'Working…');
			}
			if (bar) {
				bar.hidden = false;
			}

			function step() {
				const startAt = index * slice;
				const blob = file.slice(
					startAt,
					Math.min(file.size, startAt + slice)
				);
				return post({
					step: 'upload',
					id: uploadId,
					index: String(index),
					total: String(total),
					password: passwordValue(),
					chunk: blob,
				}).then(function (data) {
					uploadId = data.upload_id || uploadId;
					setProgress(bar, fill, data.progress);
					if (message && data.message) {
						message.textContent = data.message;
						announce(data.message);
					}
					if ('ready' === data.status) {
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
				if (message) {
					message.textContent = line;
				}
				announce(line);
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

	document.addEventListener('DOMContentLoaded', function () {
		const region = document.getElementById('bmx-live-region');
		if (region) {
			region.setAttribute('data-bmx-ready', 'true');
		}
		bindExport();
		bindImport();
	});
})();
