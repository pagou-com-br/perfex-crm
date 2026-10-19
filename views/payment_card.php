<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$invoice_url = site_url('invoice/' . (int) $invoice->id . '/' . $invoice->hash);
?>
<div style="background-color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); max-width: 560px; margin: 50px auto; font-family: Arial, sans-serif;">
    <h3 style="margin-top: 0;">Pagamento com cartão de crédito</h3>
    <p>Fatura <?php echo $e(format_invoice_number($invoice->id)); ?> · Valor: <strong>R$ <?php echo $e(number_format($amount, 2, ',', '.')); ?></strong></p>

    <div id="card-message" class="alert" style="display:none;"></div>

    <?php if ($open_charge): ?>
        <div class="alert alert-info">Já existe um pagamento com cartão em processamento para esta fatura. Esta página atualiza sozinha.</div>
    <?php else: ?>
    <form id="card-form" autocomplete="on" novalidate>
        <div class="form-group">
            <label for="card-number">Número do cartão</label>
            <!-- Sem atributo name: número e CVV nunca são enviados ao servidor -->
            <input type="text" id="card-number" class="form-control" inputmode="numeric" autocomplete="cc-number" maxlength="23" required>
        </div>
        <div class="form-group">
            <label for="card-holder">Nome impresso no cartão</label>
            <input type="text" id="card-holder" class="form-control" autocomplete="cc-name" maxlength="100" required>
        </div>
        <div class="row">
            <div class="col-xs-4 form-group">
                <label for="card-month">Mês</label>
                <select id="card-month" class="form-control" autocomplete="cc-exp-month" required>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo sprintf('%02d', $m); ?>"><?php echo sprintf('%02d', $m); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-xs-4 form-group">
                <label for="card-year">Ano</label>
                <select id="card-year" class="form-control" autocomplete="cc-exp-year" required>
                    <?php for ($y = (int) date('Y'); $y <= (int) date('Y') + 15; $y++): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-xs-4 form-group">
                <label for="card-cvv">CVV</label>
                <input type="password" id="card-cvv" class="form-control" inputmode="numeric" autocomplete="cc-csc" maxlength="4" required>
            </div>
        </div>
        <div class="form-group">
            <label for="card-installments">Parcelas</label>
            <select id="card-installments" class="form-control">
                <?php foreach ($installments as $n): ?>
                    <option value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?>x de R$ <?php echo $e(number_format($amount / $n, 2, ',', '.')); ?><?php echo $n === 1 ? ' (à vista)' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <div class="col-xs-7 form-group">
                <label for="payer-email">E-mail</label>
                <input type="email" id="payer-email" class="form-control" value="<?php echo $e($contact['email']); ?>" required>
            </div>
            <div class="col-xs-5 form-group">
                <label for="payer-phone">Telefone com DDD</label>
                <input type="tel" id="payer-phone" class="form-control" value="<?php echo $e($contact['phone']); ?>" required>
            </div>
        </div>
        <button type="submit" id="card-submit" class="btn btn-success btn-block btn-lg">Pagar R$ <?php echo $e(number_format($amount, 2, ',', '.')); ?></button>
        <p class="text-muted small" style="margin-top: 10px;">Os dados do cartão são criptografados no seu navegador antes do envio.</p>
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/node-forge@1.0.0/dist/forge.min.js" referrerpolicy="no-referrer"></script>
<script src="https://js.cel.cash/checkout-v2.min.js" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var config = <?php echo json_encode([
        'production' => (bool) $is_production,
        'chargeUrl'  => $charge_url,
        'statusUrl'  => $status_url,
        'invoiceUrl' => $invoice_url,
        'csrfName'   => $csrf_name,
        'csrfHash'   => $csrf_hash,
        'pending'    => (bool) $open_charge,
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    var message = document.getElementById('card-message');
    function show(type, text) {
        message.className = 'alert alert-' + type;
        message.textContent = text;
        message.style.display = 'block';
    }

    function poll() {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            if (attempts > 120) { clearInterval(timer); return; }
            fetch(config.statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'paid') {
                        clearInterval(timer);
                        window.location.href = config.invoiceUrl;
                    } else if (data.card && (data.card.status === 'failed' || data.card.status === 'canceled')) {
                        clearInterval(timer);
                        show('danger', 'Pagamento não aprovado' + (data.card.reason ? ': ' + data.card.reason : '.') + ' Recarregue a fatura para tentar com outro cartão.');
                    }
                })
                .catch(function () {});
        }, 5000);
    }

    if (config.pending) { poll(); return; }

    // Tokenização conforme docs.pagou.com.br (Tokenizando um cartão)
    var library = null;
    function getLibrary() {
        if (library) { return library; }
        if (typeof window.forge !== 'object' || typeof window.BaasCard !== 'function') {
            return Promise.reject(new Error('Biblioteca de pagamento indisponível.'));
        }
        library = (async function () {
            var lib = new window.BaasCard(config.production);
            await lib._setPublicKey();
            if (typeof lib._publicKey !== 'string' || lib._publicKey.length < 100) {
                throw new Error('Biblioteca de pagamento indisponível.');
            }
            return lib;
        })().catch(function (err) { library = null; throw err; });
        return library;
    }
    getLibrary().catch(function () {});

    var form = document.getElementById('card-form');
    var submit = document.getElementById('card-submit');
    var busy = false;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) { return; }
        busy = true;
        submit.disabled = true;
        message.style.display = 'none';

        var numberInput = document.getElementById('card-number');
        var cvvInput = document.getElementById('card-cvv');
        var holder = document.getElementById('card-holder').value.trim();
        var expiresAt = document.getElementById('card-year').value + '-' + document.getElementById('card-month').value;
        var card;
        try {
            var lib = await getLibrary();
            var item = lib.newCard({
                number: numberInput.value.replace(/\D/g, ''),
                holder: holder,
                expiresAt: expiresAt,
                cvv: cvvInput.value
            });
            item.validate();
            var token = item.encrypt(lib._publicKey);
            if (typeof token !== 'string' || token.length < 64 || token.length > 16384 || !/^[A-Za-z0-9+/]+={0,2}$/.test(token)) {
                throw new Error('Token inválido.');
            }
            card = { card_token: token, holder: holder, expires_at: expiresAt };
        } catch (err) {
            busy = false;
            submit.disabled = false;
            show('danger', 'Confira os dados do cartão e tente novamente.');
            return;
        } finally {
            // Número e CVV não ficam no formulário depois de tokenizar
            numberInput.value = '';
            cvvInput.value = '';
        }

        var body = new FormData();
        body.append(config.csrfName, config.csrfHash);
        body.append('card_token', card.card_token);
        body.append('holder', card.holder);
        body.append('expires_at', card.expires_at);
        body.append('installments', document.getElementById('card-installments').value);
        body.append('email', document.getElementById('payer-email').value);
        body.append('phone', document.getElementById('payer-phone').value);
        card = null;

        fetch(config.chargeUrl, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.csrf) { config.csrfHash = data.csrf; }
                if (data.result === 'paid') {
                    show('success', data.message);
                    window.location.href = config.invoiceUrl;
                } else if (data.result === 'processing') {
                    show('info', data.message);
                    form.style.display = 'none';
                    poll();
                } else {
                    busy = false;
                    submit.disabled = false;
                    show('danger', data.message || 'Não foi possível processar o pagamento.');
                }
            })
            .catch(function () {
                // Resposta perdida: o pagamento pode ter sido processado; não reenviar
                form.style.display = 'none';
                show('warning', 'Não recebemos a confirmação. Não tente de novo; esta página atualiza quando o pagamento for confirmado.');
                poll();
            });
    });
})();
</script>
