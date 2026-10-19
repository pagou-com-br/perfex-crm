<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$split_labels = [1 => 'registrado', 2 => 'em andamento', 4 => 'concluído', 5 => 'revertido', 6 => 'ação necessária'];
$method_labels = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão'];
?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?php echo $e($title); ?></h4>
            <hr class="hr-panel-heading" />
            <div class="alert alert-info">
              O split precisa de aprovação prévia da Pagou e do recurso habilitado no plano. Cada recebedor precisa estar
              <strong>autorizado no painel da Pagou</strong> (Configurações → Split → Autorizar conta). O split também precisa
              ser ligado em cada gateway (opção "SPLIT"). Valores fixos são em reais, inclusive no cartão; percentuais são calculados sobre o valor bruto.
            </div>

            <h4>Recebedores</h4>
            <table class="table table-striped">
              <thead><tr><th>Nome</th><th>UUID do cliente Pagou</th><th>Situação</th><th>Observação</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($recipients as $r): ?>
                <tr>
                  <td><?php echo $e($r->name); ?></td>
                  <td><code><?php echo $e($r->customer_uuid); ?></code></td>
                  <td><?php echo $r->active ? 'Ativo' : 'Inativo'; ?></td>
                  <td><?php echo $e($r->notes); ?></td>
                  <td>
                    <?php echo form_open(admin_url('pagou/split_admin/toggle_recipient/' . (int) $r->id), ['style' => 'display:inline']); ?>
                      <button class="btn btn-default btn-xs" type="submit"><?php echo $r->active ? 'Desativar' : 'Ativar'; ?></button>
                    <?php echo form_close(); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php echo form_open(admin_url('pagou/split_admin/add_recipient'), ['class' => 'form-inline']); ?>
              <input class="form-control" name="name" placeholder="Nome do recebedor" required>
              <input class="form-control" name="customer_uuid" placeholder="UUID do cliente Pagou" size="40" required>
              <input class="form-control" name="notes" placeholder="Observação (opcional)">
              <button class="btn btn-primary" type="submit">Cadastrar recebedor</button>
            <?php echo form_close(); ?>

            <hr />
            <h4>Regras</h4>
            <p class="text-muted">As regras do cliente substituem as globais para aquele cliente. Até 10 recebedores por pagamento.</p>
            <table class="table table-striped">
              <thead><tr><th>Escopo</th><th>Recebedor</th><th>Tipo</th><th>Valor</th><th>Meios</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($rules as $rule): ?>
                <tr>
                  <td><?php echo $rule->scope === 'client' ? 'Cliente: ' . $e($rule->client_company ?: '#' . $rule->scope_id) : 'Global'; ?></td>
                  <td><?php echo $e($rule->recipient_name); ?></td>
                  <td><?php echo $rule->type === 'percentage' ? 'Percentual' : ($rule->type === 'none' ? 'Sem split' : 'Fixo (R$)'); ?></td>
                  <td><?php echo $rule->type === 'none' ? '—' : ($rule->type === 'percentage' ? $e(rtrim(rtrim(number_format($rule->value, 4, ',', ''), '0'), ',')) . '%' : 'R$ ' . $e(number_format($rule->value, 2, ',', '.'))); ?></td>
                  <td><?php echo $e(implode(', ', array_map(function ($m) use ($method_labels) { return $method_labels[$m] ?? $m; }, explode(',', $rule->methods)))); ?></td>
                  <td>
                    <?php echo form_open(admin_url('pagou/split_admin/delete_rule/' . (int) $rule->id), ['style' => 'display:inline', 'onsubmit' => "return confirm('Remover esta regra?');"]); ?>
                      <button class="btn btn-danger btn-xs" type="submit">Remover</button>
                    <?php echo form_close(); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php if ($recipients): ?>
            <?php echo form_open(admin_url('pagou/split_admin/add_rule'), ['class' => 'form-inline']); ?>
              <select class="form-control" name="scope" onchange="document.getElementById('split-client').style.display = this.value === 'client' ? 'inline-block' : 'none';">
                <option value="global">Global</option>
                <option value="client">Por cliente</option>
              </select>
              <input class="form-control" id="split-client" name="client_id" type="number" min="1" placeholder="ID do cliente" style="display:none; width: 130px;">
              <select class="form-control" name="recipient_id">
                <?php foreach ($recipients as $r): if (!$r->active) { continue; } ?>
                  <option value="<?php echo (int) $r->id; ?>"><?php echo $e($r->name); ?></option>
                <?php endforeach; ?>
              </select>
              <select class="form-control" name="type">
                <option value="percentage">Percentual</option>
                <option value="fixed">Fixo (R$, proporcional ao saldo cobrado)</option>
                <option value="none">Sem split (isentar o cliente)</option>
              </select>
              <input class="form-control" name="value" placeholder="Valor" style="width: 100px;" required>
              <label><input type="checkbox" name="methods[]" value="pix" checked> Pix</label>
              <label><input type="checkbox" name="methods[]" value="boleto" checked> Boleto</label>
              <label><input type="checkbox" name="methods[]" value="cartao" checked> Cartão</label>
              <button class="btn btn-primary" type="submit">Cadastrar regra</button>
            <?php echo form_close(); ?>
            <?php endif; ?>

            <hr />
            <h4>Margem para tarifas</h4>
            <?php echo form_open(admin_url('pagou/split_admin/save_margin'), ['class' => 'form-inline']); ?>
              <label>As regras não podem passar de (100 − margem)% do valor bruto. Margem mínima (%):</label>
              <input class="form-control" name="margin" value="<?php echo $e($margin); ?>" style="width: 80px;">
              <button class="btn btn-default" type="submit">Salvar</button>
            <?php echo form_close(); ?>

            <hr />
            <h4>Últimas distribuições</h4>
            <table class="table table-condensed">
              <thead><tr><th>Data</th><th>Meio</th><th>Cobrança</th><th>Fatura</th><th>Status do split</th><th>Tarifa</th></tr></thead>
              <tbody>
              <?php foreach ($snapshots as $snap): ?>
                <tr>
                  <td><?php echo $e(_dt($snap->created_at)); ?></td>
                  <td><?php echo $e($method_labels[$snap->method] ?? $snap->method); ?></td>
                  <td><code><?php echo $e($snap->charge_id); ?></code></td>
                  <td><a href="<?php echo $e(admin_url('invoices/list_invoices/' . (int) $snap->invoice_id)); ?>">#<?php echo (int) $snap->invoice_id; ?></a></td>
                  <td><?php echo $e($split_labels[(int) $snap->split_status] ?? ($snap->split_status === null ? '—' : $snap->split_status)); ?></td>
                  <td><?php echo $snap->fee !== null ? 'R$ ' . $e(number_format($snap->fee, 2, ',', '.')) : '—'; ?></td>
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
<?php init_tail(); ?>
</body>
</html>
