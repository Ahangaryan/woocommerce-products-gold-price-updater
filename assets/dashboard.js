/* Gold Price Updater — Dashboard JavaScript */
(function () {
	'use strict';

	// Wait for DOM
	document.addEventListener('DOMContentLoaded', function () {
		initTabs();
		initProviderPreset();
		initProgressPolling();
		initSyncButton();
	});

	// --- Tab Switching ---
	function initTabs() {
		var tabs = document.querySelectorAll('.gpu-tab');
		var tabContents = document.querySelectorAll('.gpu-tab-content');

		if (!tabs.length || !tabContents.length) return;

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				var tabName = this.getAttribute('data-tab');

				// Update active tab
				tabs.forEach(function (t) {
					t.classList.remove('active');
				});
				this.classList.add('active');

				// Show/hide content
				tabContents.forEach(function (content) {
					if (content.getAttribute('data-tab') === tabName) {
						content.style.display = 'block';
					} else {
						content.style.display = 'none';
					}
				});
			});
		});

		// Show first tab by default
		if (tabContents.length > 0) {
			tabContents[0].style.display = 'block';
		}
	}

	// --- Provider Preset Auto-fill ---
	function initProviderPreset() {
		var providerSelect = document.querySelector('select[name="gpu_provider"]');
		if (!providerSelect || typeof gpuProviders === 'undefined') return;

		providerSelect.addEventListener('change', function () {
			var key = this.value;
			var preset = gpuProviders[key];
			if (!preset) return;

			// Auto-fill all fields from preset
			var fields = {
				'gpu_api_url': preset.url || '',
				'gpu_json_path': preset.json_path || ''
			};

			var selects = {
				'gpu_auth': preset.auth || 'none',
				'gpu_price_unit': preset.unit || 'gram_18k',
				'gpu_api_currency': preset.currency || 'IRR'
			};

			// Update text/url inputs
			Object.keys(fields).forEach(function (name) {
				var field = document.querySelector('input[name="' + name + '"]');
				if (field) field.value = fields[name];
			});

			// Update selects
			Object.keys(selects).forEach(function (name) {
				var field = document.querySelector('select[name="' + name + '"]');
				if (field) field.value = selects[name];
			});
		});
	}

	// --- Progress Polling ---
	function initProgressPolling() {
		var progressBar = document.getElementById('gpu-progress-bar');
		var progressText = document.getElementById('gpu-progress-text');

		if (!progressBar || !progressText || typeof gpuDash === 'undefined') return;

		function renderProgress(data) {
			progressBar.style.width = data.percent + '%';
			var c = data.counts;
			progressText.innerHTML =
				'<span>✅ ' + c.updated + ' بروزرسانی</span>' +
				'<span>⏭️ ' + c.skipped + ' رد شده</span>' +
				'<span>❌ ' + c.error + ' خطا</span>' +
				'<span>⏳ ' + c.pending + ' در انتظار</span>';
		}

		function pollProgress() {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', gpuDash.ajaxUrl, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
			xhr.onload = function () {
				try {
					var response = JSON.parse(xhr.responseText);
					if (response.success) {
						renderProgress(response.data);
						setTimeout(pollProgress, response.data.running ? 2000 : 10000);
						return;
					}
				} catch (e) { /* ignore */ }
				setTimeout(pollProgress, 15000);
			};
			xhr.send('action=gpu_progress&nonce=' + encodeURIComponent(gpuDash.nonce));
		}

		pollProgress();
	}

	// --- Sync Button Loading State ---
	function initSyncButton() {
		var syncBtn = document.querySelector('.gpu-btn-success');
		if (!syncBtn) return;

		syncBtn.addEventListener('click', function () {
			this.innerHTML = '⏳ در حال بروزرسانی...';
			this.style.opacity = '0.7';
			this.style.pointerEvents = 'none';
		});
	}

})();
