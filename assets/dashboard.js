/* Gold Price Updater — dashboard: provider presets + progress polling. */
(function () {
	'use strict';

	// --- Provider preset auto-fill ---
	var providerSelect = document.querySelector('select[name="gpu_provider"]');
	if (providerSelect && typeof gpuProviders !== 'undefined') {
		providerSelect.addEventListener('change', function () {
			var key = this.value;
			var preset = gpuProviders[key];
			if (!preset) return;
			// Only auto-fill empty fields — don't overwrite custom values.
			var urlField = document.querySelector('input[name="gpu_api_url"]');
			if (urlField && !urlField.value.trim()) {
				urlField.value = preset.url || '';
			}
			var authField = document.querySelector('select[name="gpu_auth"]');
			if (authField) {
				authField.value = preset.auth || 'none';
			}
			var jsonField = document.querySelector('input[name="gpu_json_path"]');
			if (jsonField && !jsonField.value.trim()) {
				jsonField.value = preset.json_path || '';
			}
			var unitField = document.querySelector('select[name="gpu_price_unit"]');
			if (unitField) {
				unitField.value = preset.unit || 'gram_18k';
			}
			var curField = document.querySelector('select[name="gpu_api_currency"]');
			if (curField) {
				curField.value = preset.currency || 'IRR';
			}			});

		// Auto-fill on page load if provider is set but URL is empty.
		var urlField = document.querySelector('input[name="gpu_api_url"]');
		if (urlField && !urlField.value.trim() && providerSelect.value) {
			providerSelect.dispatchEvent(new Event('change'));
		}
	}

	// --- Progress polling ---
	var bar = document.getElementById('gpu-progress-bar');
	var text = document.getElementById('gpu-progress-text');
	if (!bar || !text || typeof gpuDash === 'undefined') {
		return;
	}

	function render(data) {
		bar.style.width = data.percent + '%';
		var c = data.counts;
		text.textContent = data.percent + '% — ' + c.updated + ' updated, ' + c.skipped + ' skipped, ' + c.error + ' errors, ' + c.pending + ' pending';
	}

	function poll() {
		var request = new XMLHttpRequest();
		request.open('POST', gpuDash.ajaxUrl, true);
		request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		request.onload = function () {
			try {
				var response = JSON.parse(request.responseText);
				if (response.success) {
					render(response.data);
					// Keep polling while the queue is working.
					setTimeout(poll, response.data.running ? 2000 : 10000);
					return;
				}
			} catch (e) {
				// Ignore malformed responses; retry on the next tick.
			}
			setTimeout(poll, 15000);
		};
		request.send('action=gpu_progress&nonce=' + encodeURIComponent(gpuDash.nonce));
	}

	poll();
})();
