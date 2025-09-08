<div class="payment-status">
    <!-- ============================
         Estrutura de Linha
         ============================ -->
    <div class="row">
        <!-- ----------------------------
             Informações do Status do Pagamento
             ---------------------------- -->
        <div class="col-md-12">
            <i class="fa fa-clock-o"></i>
            <strong>Status do Pagamento:</strong>
            <?php
            // Definição do texto e classe CSS com base no status do pagamento
            $status_text = 'Aguardando Pagamento';
            $status_class = 'text-warning';
            
            if ($status === 'paid') {
                $status_text = 'Pagamento Confirmado';
                $status_class = 'text-success';
            } elseif ($status === 'canceled') {
                $status_text = 'Pagamento Cancelado';
                $status_class = 'text-danger';
            }
            ?>
            <!-- Exibição do status com a classe CSS correspondente -->
            <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
        </div>
    </div>
</div>
