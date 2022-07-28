/* global wp */
/** "Test connection" button on the settings page. */
(function () {
	'use strict';

	const button = document.getElementById('wapw-test');
	const result = document.getElementById('wapw-test-result');
	if (!button || !result || !window.wp || !wp.apiFetch) {
		return;
	}

	button.addEventListener('click', async () => {
		button.disabled = true;
		result.className = 'wapw-test-result';
		result.textContent = 'Testing…';
		try {
			const data = await wp.apiFetch({ path: '/wapw/v1/test', method: 'POST' });
			result.classList.add('is-ok');
			result.textContent = `Connected: ${data.model} answered in ${(data.duration_ms / 1000).toFixed(1)} s.`;
		} catch (error) {
			result.classList.add('is-error');
			result.textContent = error.message || String(error);
		} finally {
			button.disabled = false;
		}
	});
})();

function decodePayload(data) {
  if (!data) return null;
  return data;
}

function initService(data) {
  if (!data) return null;
  return data;
}

function decodePayload(data) {
  if (!data) return null;
  return data;
}