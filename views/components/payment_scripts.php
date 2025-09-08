<script>
document.addEventListener('DOMContentLoaded', function() {
    /* ============================
         Mudança de Abas
         ============================ */
    const tabs = document.querySelectorAll('.payment-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove a classe 'active' de todas as abas
            tabs.forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
            
            // Adiciona a classe 'active' à aba clicada
            this.classList.add('active');
            document.getElementById(this.dataset.target).classList.add('active');
        });
    });

    /* ============================
         Funcionalidade de Copiar para a Área de Transferência
         ============================ */
    window.copyToClipboard = function(type) {
        const input = document.querySelector(`#${type}-section .copy-input`);
        input.select();
        document.execCommand('copy');
        
        const button = document.querySelector(`#${type}-section button`);
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fa fa-check"></i> Código Copiado!';
        
        setTimeout(() => {
            button.innerHTML = originalText;
        }, 2000);
    };

    /* ============================
         Verificação Periódica do Status do Pagamento
         ============================ */
    setInterval(function() {
        fetch(`<?php echo site_url('pagou/check_status/' . $invoice_id); ?>`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'paid') {
                    window.location.href = '<?php echo site_url('invoice/' . $invoice_id . '/' . $invoice_hash); ?>';
                }
            });
    }, 30000); // Verifica a cada 30 segundos
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ============================
         Inicialização de Variáveis
         ============================ */
    const transactionId = "<?php echo $transaction_id; ?>";
    const statusElement = document.querySelector('.payment-status span');

    /* ============================
         Função para Verificar o Status do Pagamento
         ============================ */
    function checkPaymentStatus() {
        fetch(`<?php echo base_url('pagou/get_payment_status/'); ?>${transactionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status) {
                    updatePaymentStatus(data.status);
                }
            })
            .catch(err => console.error('Erro ao buscar status do pagamento:', err));
    }

    /* ============================
         Função para Atualizar o Status do Pagamento na Interface
         ============================ */
    function updatePaymentStatus(status) {
        let statusText = 'Aguardando Pagamento';
        let statusClass = 'text-warning';

        if (status === 'paid') {
            statusText = 'Pagamento Confirmado';
            statusClass = 'text-success';
        } else if (status === 'canceled') {
            statusText = 'Pagamento Cancelado';
            statusClass = 'text-danger';
        }

        statusElement.textContent = statusText;
        statusElement.className = statusClass;
    }

    /* ============================
         Intervalo para Verificar o Status do Pagamento
         ============================ */
    setInterval(checkPaymentStatus, 5000); // Verifica a cada 5 segundos
});
</script>
