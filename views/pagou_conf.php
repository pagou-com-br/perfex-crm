<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<style>
  /* Estilo simples para a página */
  .pagou-config-title {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 15px;
  }
  .pagou-divider {
    margin-bottom: 20px;
  }
</style>

<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">

        <div class="panel_s">
          <div class="panel-body">
            
            <h4 class="pagou-config-title">Configurações Adicionais ao Pagou.com</h4>
            <hr class="pagou-divider" />

            <!-- Observe que é tudo minúsculo após admin_url('pagou_conf/save') -->
            <?php echo form_open(admin_url('pagou/pagou_conf/save')); ?>
              <div class="form-group">
                <label for="logs_pagou_api"><strong>Logs Pagou API:</strong></label>
                
                <div class="radio radio-primary" style="margin-bottom: 10px;">
                  <input type="radio" id="logs_pagou_api_1" name="logs_pagou_api"
                         value="1" <?php echo ($logs_pagou_api == '1' ? 'checked' : ''); ?>>
                  <label for="logs_pagou_api_1">Ativar</label>
                </div>

                <div class="radio radio-primary">
                  <input type="radio" id="logs_pagou_api_0" name="logs_pagou_api"
                         value="0" <?php echo ($logs_pagou_api == '0' ? 'checked' : ''); ?>>
                  <label for="logs_pagou_api_0">Desativar</label>
                </div>
              </div>

              <button type="submit" class="btn btn-primary">Salvar</button>
            <?php echo form_close(); ?>

            <hr />
            <h4>Chaves de API anteriores</h4>
            <p class="text-muted">Depois de trocar a chave de API, o módulo ainda aceita por 30 dias webhooks assinados com a chave anterior, para não perder confirmações de cobranças já emitidas. Se a chave anterior vazou, esqueça-a agora.</p>
            <?php echo form_open(admin_url('pagou/pagou_conf/forget_keys')); ?>
              <button type="submit" class="btn btn-danger" onclick="return confirm('Esquecer as chaves de API anteriores?');">Esquecer chaves anteriores</button>
            <?php echo form_close(); ?>

          </div>
        </div><!-- end panel_s -->

      </div>
    </div>
  </div>
</div>

<?php init_tail(); ?>
</body>
</html>
