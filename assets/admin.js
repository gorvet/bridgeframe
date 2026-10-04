(function () {
    if (typeof BridgeframeAdmin === 'undefined') {
        return;
    }

    const messages = BridgeframeAdmin.messages || {};

    function setText(element, text) {
        if (element) {
            element.textContent = text;
        }
    }

    function postAjax(action) {
        return fetch(BridgeframeAdmin.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: action,
                _ajax_nonce: BridgeframeAdmin.nonce,
            }),
        }).then(function (response) {
            return response.json();
        });
    }

    const tokenButton = document.getElementById('regenerate-token-btn');

    if (tokenButton) {
        tokenButton.addEventListener('click', function () {
            const tokenEl = document.getElementById('bridgeframe-token');
            const statusEl = document.getElementById('token-status');

            tokenButton.disabled = true;
            setText(statusEl, messages.generating || 'Generando...');

            postAjax(BridgeframeAdmin.tokenAction)
                .then(function (data) {
                    if (data.success && data.data && data.data.token) {
                        setText(tokenEl, data.data.token);
                        setText(statusEl, messages.generated || 'Generado');
                        return;
                    }

                    setText(statusEl, messages.error || 'Error');
                })
                .catch(function () {
                    setText(statusEl, messages.error || 'Error');
                })
                .finally(function () {
                    tokenButton.disabled = false;
                });
        });
    }

    const cacheButton = document.getElementById('bridgeframe-clear-cache-btn');

    if (cacheButton) {
        cacheButton.addEventListener('click', function () {
            const statusEl = document.getElementById('bridgeframe-cache-status');

            cacheButton.disabled = true;
            setText(statusEl, messages.clearing || 'Limpiando...');

            postAjax(BridgeframeAdmin.clearCacheAction)
                .then(function (data) {
                    setText(statusEl, data.success ? (messages.cleared || 'Caché limpio') : (messages.error || 'Error'));
                })
                .catch(function () {
                    setText(statusEl, messages.error || 'Error');
                })
                .finally(function () {
                    cacheButton.disabled = false;
                });
        });
    }
})();
