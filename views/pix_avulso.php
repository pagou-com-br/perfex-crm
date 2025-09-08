<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<style>
    
/* Variáveis e configurações iniciais */
:root {
  --primary-color: #5a67d8;
  --accent-color: #ed64a6;
  --secondary-color: #48bb78;
  --background-color: #f0f4f8;
  --card-background: #ffffff;
  --text-color: #333;
  --border-color: #e2e8f0;
  --input-focus-color: #5a67d8;
  --shadow-color: rgba(0, 0, 0, 0.1);
  --transition-speed: 0.3s;
}

* {
  box-sizing: border-box;
}

/* Animação de fade in para a página */
body {
  background: linear-gradient(135deg, #f0f4f8, #ffffff);
  font-family: 'Poppins', 'Segoe UI', Tahoma, sans-serif;
  color: var(--text-color);
  margin: 0;
  padding: 0;
  animation: fadeIn 1s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Centralização e responsividade do conteúdo */
#wrapper {
  max-width: 1200px;
  margin: 40px auto;
  padding: 20px;
}

/* Estilo de "card" para o container principal */
.content {
  background: var(--card-background);
  padding: 40px;
  border-radius: 12px;
  box-shadow: 0 4px 12px var(--shadow-color);
}

/* Painel sem bordas e com cantos arredondados */
.panel_s {
  border: none;
  border-radius: 12px;
  overflow: hidden;
}

.panel-body {
  padding: 40px;
}

/* Estilização dos títulos */
h2, h3 {
  color: var(--primary-color);
  text-align: center;
  margin-bottom: 20px;
  font-weight: 700;
}

h2 {
  font-size: 2.2em;
}

h3 {
  font-size: 1.8em;
}

/* Linha de separação customizada */
hr {
  border: none;
  height: 2px;
  background: var(--border-color);
  margin: 30px auto;
  width: 80%;
  border-radius: 1px;
}

/* Estilo dos grupos de formulário */
.form-group {
  margin-bottom: 25px;
}

.form-group label {
  font-size: 0.95rem;
  font-weight: 600;
  margin-bottom: 5px;
  display: block;
}

/* Inputs modernos com transição e foco customizado */
.form-control {
  width: 100%;
  padding: 12px 15px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  transition: border-color var(--transition-speed), box-shadow var(--transition-speed);
  font-size: 1rem;
}

.form-control:focus {
  border-color: var(--input-focus-color);
  box-shadow: 0 0 8px rgba(90, 103, 216, 0.3);
  outline: none;
}

/* Botão com gradiente, animação de hover e toque moderno */
.btn-primary {
  background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
  border: none;
  border-radius: 8px;
  padding: 12px 30px;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: background var(--transition-speed), transform 0.2s;
  color: #fff;
  display: inline-block;
  text-align: center;
}

.btn-primary:hover {
  background: linear-gradient(45deg, var(--accent-color), var(--primary-color));
  transform: translateY(-2px);
}

/* Tabela com responsividade, linhas zebra e animação de hover */
.table-responsive {
  overflow-x: auto;
  margin-top: 30px;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.95rem;
}

.table th, .table td {
  padding: 15px;
  border: 1px solid var(--border-color);
  text-align: left;
}

.table th {
  background-color: var(--primary-color);
  color: #fff;
}

.table tr:nth-child(even) {
  background-color: #f9f9f9;
}

.table tr:hover {
  background-color: #f1f1f1;
  transition: background-color var(--transition-speed);
}

/* Ícone de visualização com transição */
.fa-eye {
  color: var(--primary-color);
  transition: color var(--transition-speed);
}

.fa-eye:hover {
  color: var(--accent-color);
}

/* Media Queries para dispositivos móveis */
@media (max-width: 768px) {
  .content, .panel-body {
    padding: 20px;
  }
  
  h2 {
    font-size: 1.8em;
  }
  
  h3 {
    font-size: 1.5em;
  }
  
  .form-control, .btn-primary {
    font-size: 0.9rem;
  }
}

    
</style>

<div id="wrapper">
   <div class="content">
      <div class="row">
         <div class="col-md-12">
            <div class="panel_s">
               <div class="panel-body">
                  <!-- ======================================== -->
                  <!-- == ADIÇÃO/ALTERAÇÃO: Atualiza Status == -->
                  <!-- ======================================== -->
                  <?php
                  // Precisamos de acesso ao objeto CI para atualizar a base de dados a partir da view
                  // e marcar como 'cancelado' quando o prazo de validade expirar (24h).
                  $CI =& get_instance();
                  if (!empty($charges)) {
                      foreach ($charges as $charge) {
                          // Considerando que a data de emissão está em $charge->data_emissao
                          // e o prazo de validade é 24h (86400 segundos)
                          $expirationTime = strtotime($charge->data_emissao) + 86400;

                          if (time() > $expirationTime 
                              && ($charge->status === 'emitido' || $charge->status === 'pendente')
                          ) {
                              // Atualiza no banco
                              $CI->db->where('id', $charge->id);
                              $CI->db->update('pix_avulso', ['status' => 'cancelado']);

                              // Atualiza também o objeto em memória, para exibir corretamente
                              $charge->status = 'cancelado';
                          }
                      }
                  }
                  ?>
                  <!-- ======================================== -->
                  
                  <!-- Título centralizado -->
                  <h2 class="text-center" style="font-family: 'Arial', sans-serif;">
                     <?php echo _l('Emissão de PIX Avulso'); ?>
                  </h2>
                  
                  <!-- Linha de corte antes do formulário -->
                  <hr style="width:80%; margin:20px auto; border-top:2px solid #ccc;">
                  
                  <!-- Formulário para emissão da cobrança -->
                  <?php echo form_open(admin_url('pagou/pix_avulso/create')); ?>
                     <div class="row">
                        <div class="col-md-6">
                           <div class="form-group">
                              <label for="nome"><?php echo _l('Pagador - Nome'); ?></label>
                              <input type="text" name="nome" id="nome" class="form-control" required>
                           </div>
                        </div>
                        <div class="col-md-6">
                           <div class="form-group">
                              <label for="cpf"><?php echo _l('Pagador - CPF'); ?></label>
                              <input type="text" name="cpf" id="cpf" class="form-control" required>
                           </div>
                        </div>
                     </div>
                     <div class="form-group">
                        <label for="descricao"><?php echo _l('Descrição do Pagamento'); ?></label>
                        <textarea name="descricao" id="descricao" class="form-control" rows="3" required></textarea>
                     </div>
                     <div class="form-group">
                        <label for="valor"><?php echo _l('Valor da Cobrança'); ?></label>
                        <input type="number" name="valor" id="valor" class="form-control" step="0.01" required>
                     </div>
                     <button type="submit" class="btn btn-primary">
                        <?php echo _l('Emitir a Cobrança'); ?>
                     </button>
                  <?php echo form_close(); ?>
                  
                  <!-- Linha de corte abaixo do formulário -->
                  <hr style="width:80%; margin:20px auto; border-top:2px solid #ccc;">
                  
                  <!-- Tabela de cobranças emitidas -->
                  <h3 class="text-center"><?php echo _l('Cobranças Emitidas'); ?></h3>
                  <div class="table-responsive">
                     <table class="table table-bordered">
                        <thead>
                           <tr>
                              <th><?php echo _l('Cliente'); ?></th>
                              <th><?php echo _l('CPF'); ?></th>
                              <th><?php echo _l('Descrição'); ?></th>
                              <th><?php echo _l('Valor'); ?></th>
                              <th><?php echo _l('Status'); ?></th>
                              <th><?php echo _l('Visualizar'); ?></th>
                           </tr>
                        </thead>
                        <tbody>
                           <?php if (!empty($charges)) { ?>
                              <?php foreach ($charges as $charge) { ?>
                                 <tr>
                                    <td><?php echo htmlspecialchars($charge->nome, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($charge->cpf, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($charge->descricao, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($charge->valor, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($charge->status, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-center">
                                       <!-- ======================================== -->
                                       <!-- == ADIÇÃO/ALTERAÇÃO: Abrir em Modal   == -->
                                       <!-- ======================================== -->
                                       <a href="javascript:void(0);" 
                                          title="<?php echo _l('Visualizar'); ?>"
                                          onclick="openChargeModal('<?php echo admin_url('pagou/pix_avulso/view/' . $charge->link); ?>')">
                                          <i class="fa fa-eye"></i>
                                       </a>
                                       <!-- ======================================== -->
                                    </td>
                                 </tr>
                              <?php } ?>
                           <?php } else { ?>
                              <tr>
                                 <td colspan="6" class="text-center"><?php echo _l('Nenhuma cobrança emitida.'); ?></td>
                              </tr>
                           <?php } ?>
                        </tbody>
                     </table>
                  </div>
                  
               </div><!-- panel-body -->
            </div><!-- panel_s -->
         </div><!-- col-md-12 -->
      </div><!-- row -->
   </div><!-- content -->
</div><!-- wrapper -->

<!-- ======================================== -->
<!-- == ADIÇÃO: Modal (iframe)            == -->
<!-- ======================================== -->
<div class="modal fade" id="chargeModal" tabindex="-1" role="dialog" aria-hidden="true">
   <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
         <div class="modal-header">
            <h4 class="modal-title"><?php echo _l('Detalhes da Cobrança'); ?></h4>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
               <span aria-hidden="true">&times;</span>
            </button>
         </div>
         <div class="modal-body" style="padding: 0;">
            <iframe id="modalIframe" style="width:100%; height:600px; border:none;"></iframe>
         </div>
      </div>
   </div>
</div>

<script>
   function openChargeModal(url){
       var iframe = document.getElementById('modalIframe');
       iframe.src = url;
       // Abre o modal
       $('#chargeModal').modal('show');
   }
</script>
<!-- ======================================== -->

<?php init_tail(); ?>
