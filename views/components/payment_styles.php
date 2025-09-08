<style>
/* ============================
     Container Principal de Pagamento
   ============================ */
.payment-container {
    max-width: 900px;
    margin: 30px auto;
    padding: 20px;
}

/* ============================
     Abas de Métodos de Pagamento
   ============================ */
.payment-tabs {
    display: flex;
    justify-content: center;
    margin-bottom: 30px;
    border-bottom: 2px solid #e5e5e5;
}

.payment-tab {
    padding: 15px 30px;
    font-size: 16px;
    font-weight: 500;
    color: #6c757d;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
}

.payment-tab.active {
    color: #0066cc;
}

.payment-tab.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 2px;
    background: #0066cc;
}

/* ============================
     Métodos de Pagamento
   ============================ */
.payment-method {
    display: none;
}

.payment-method.active {
    display: block;
}

/* ============================
     Cabeçalho da Fatura
   ============================ */
.payment-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

/* ============================
     Seção de Cópia de Dados
   ============================ */
.copy-section {
    background: #dee2e6;
    border: 1px solid #ced4da;
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
}

.copy-input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    margin-bottom: 10px;
    font-family: monospace;
}

/* ============================
     Contêiner de QR Code
   ============================ */
.qr-code-container {
    text-align: center;
    margin: 30px 0;
}

.qr-code-image {
    max-width: 300px;
    margin: 0 auto;
}

/* ============================
     Status do Pagamento
   ============================ */
.payment-status {
    background: #e9ecef;
    padding: 15px;
    border-radius: 8px;
    margin: 20px 0;
}

/* ============================
     Seção de Informações Importantes
   ============================ */
.info-section {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}
</style>
