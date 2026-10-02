/* Gold Price Updater — product-edit price preview (accessories + overrides aware). */
(function () {
	'use strict';

	var btn = document.getElementById('gpu-preview-btn');
	var box = document.getElementById('gpu-preview-result');
	var input = document.getElementById('gpu_making_charge');
	if (!btn || !box || !input || typeof gpuPreview === 'undefined') {
		return;
	}

	function val(id) {
		var el = document.getElementById(id);
		return el ? el.value : '';
	}

	btn.addEventListener('click', function () {
		var productId = document.getElementById('post_ID');
		if (!productId) {
			return;
		}

		var body = 'action=gpu_preview_price&nonce=' + encodeURIComponent(gpuPreview.nonce) +
			'&product_id=' + encodeURIComponent(productId.value) +
			'&making_charge=' + encodeURIComponent(val('gpu_making_charge')) +
			'&accessory_weight=' + encodeURIComponent(val('gpu_accessory_weight')) +
			'&stone_fee=' + encodeURIComponent(val('gpu_stone_fee')) +
			'&seller_profit=' + encodeURIComponent(val('gpu_seller_profit')) +
			'&vat=' + encodeURIComponent(val('gpu_vat')) +
			'&acc_in_making=' + (document.getElementById('gpu_acc_in_making') &&
				document.getElementById('gpu_acc_in_making').checked ? 'yes' : 'no');

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
					var lines = [
						'Raw gold value: ' + d.raw_gold_value,
						'Making charge: ' + d.making_charge
					];
					if (d.accessory_gold_value > 0) {
						lines.push('Accessory gold value: ' + d.accessory_gold_value);
					}
					if (d.stone_fee > 0) {
						lines.push('Stone fee: ' + d.stone_fee);
					}
					lines.push('Seller profit: ' + d.seller_profit);
					lines.push('VAT: ' + d.vat);
					lines.push('Final price: ' + d.final_price);
					box.textContent = lines.join('\n');
				} else {
					box.textContent = (response.data && response.data.message) ? response.data.message : 'Preview failed.';
				}
				box.style.display = 'block';
			} catch (e) {
				box.textContent = 'Preview failed.';
				box.style.display = 'block';
			}
		};
		request.send(body);
	});
})();
