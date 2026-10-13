/**
 * BrightMigrate Pro admin entry.
 */

(function () {
	'use strict';

	/**
	 * Mark the polite live region so later modules can announce progress.
	 *
	 * @return {void}
	 */
	function bindLiveRegion() {
		const region = document.getElementById('bmx-live-region');
		if (!region) {
			return;
		}
		region.setAttribute('data-bmx-ready', 'true');
	}

	document.addEventListener('DOMContentLoaded', bindLiveRegion);
})();
