/* Gold Price Updater — product-edit price preview. */
(function () {
	'use strict';

	var btn = document.getElementById('gpu-preview-btn');
	var box = document.getElementById('gpu-preview-result');
	var input = document.getElementById('gpu_making_charge');
	if (!btn || !box || !input || typeof gpuPreview === 'undefined') {
		return;
	}

	btn.addEventListener('click', function () {
		var productId = document.getElementById('post_ID');
		if (!productId) {
			return;
		}

		var body = 'action=gpu_preview_price&nonce=' + encodeURIComponent(gpuPreview.nonce) +
			'&product_id=' + encodeURIComponent(productId.value) +
			'&making_charge=' + encodeURIComponent(input.value);

		btn.disabled = true;
		box.style.display = 'block';
		box.textContent = '...';

		var request = new XMLHttpRequest();
		request.open('POST', gpuPreview.ajaxUrl, true);
		request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		request.onload = function () {
			btn.disabled = false;
			try {
				var response = JSON.parse(request.responseText);
				if (response.success) {
					var d = response.data;
					box.textContent = [
						'Raw gold value: ' + d.raw_gold_value,
						'Making charge: ' + d.making_charge,
						'Seller profit: ' + d.seller_profit,
						'VAT: ' + d.vat,
						'Final price: ' + d.final_price
					].join('\n');
				} else {
					box.textContent = (response.data && response.data.message) ? response.data.message : 'Preview failed.';
				}
			} catch (e) {
				box.textContent = 'Preview failed.';
			}
		};
		request.send(body);
	});
})();
