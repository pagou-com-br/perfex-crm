<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }; ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?php echo $e($title); ?></h4>
            <hr class="hr-panel-heading" />
            <p class="text-muted">A baixa da fatura acontece quando a cobrança chega a <strong>captured</strong> ou <strong>settled</strong>. O estorno é integral; depois dele, ajuste o pagamento da fatura.</p>
            <div class="table-responsive">
              <table class="table table-striped">
                <thead>
                  <tr>
                    <th>Data</th><th>Fatura</th><th>Valor</th><th>Parcelas</th><th>Status</th><th>Motivo</th><th>Cobrança Pagou</th><th></th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($charges)): ?>
                  <tr><td colspan="8" class="text-center text-muted">Nenhuma cobrança de cartão.</td></tr>
                <?php endif; ?>
                <?php foreach ($charges as $c): ?>
                  <tr>
                    <td><?php echo $e(_dt($c->created_at)); ?></td>
                    <td><a href="<?php echo $e(admin_url('invoices/list_invoices/' . (int) $c->invoice_id)); ?>"><?php echo $e(($c->invoice_prefix ?? '') . ($c->invoice_number ?? '#' . $c->invoice_id)); ?></a></td>
                    <td>R$ <?php echo $e(number_format($c->value_cents / 100, 2, ',', '.')); ?></td>
                    <td><?php echo (int) $c->installments; ?>x</td>
                    <td><span class="label label-default"><?php echo $e($c->status); ?></span></td>
                    <td><?php echo $e($c->failure_reason); ?></td>
                    <td><code><?php echo $e($c->pagou_charge_id ?: $c->idempotency_key); ?></code></td>
                    <td class="text-nowrap">
                      <?php if ($c->pagou_charge_id || in_array($c->status, ['uncertain', 'creating'], true)): ?>
                        <a class="btn btn-default btn-xs" href="<?php echo $e(admin_url('pagou/card_admin/refresh/' . (int) $c->id)); ?>">Consultar</a>
                      <?php endif; ?>
                      <?php if ($can_reverse && in_array($c->status, ['authorized', 'captured', 'settled'], true)): ?>
                        <?php echo form_open(admin_url('pagou/card_admin/reverse/' . (int) $c->id), ['style' => 'display:inline', 'onsubmit' => "return confirm('Estornar integralmente esta cobrança de cartão?');"]); ?>
                          <input type="hidden" name="reason" value="Estorno solicitado pelo estabelecimento">
                          <button type="submit" class="btn btn-danger btn-xs">Estornar</button>
                        <?php echo form_close(); ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
