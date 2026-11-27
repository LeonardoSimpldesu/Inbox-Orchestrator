# Proteções de segurança restantes: plano

> Execução: inline nesta sessão (sem subagentes), com TDD.

**Spec:** `docs/superpowers/specs/2026-11-27-security-hardening-design.md`

### Tarefa 1: limite de login por e-mail
- [ ] Teste: 20 tentativas para o mesmo e-mail, cada uma de um IP diferente, passam; a 21ª recebe 429. O limite de 5 por minuto por e-mail + IP continua.
- [ ] `FortifyServiceProvider`: o limiter `login` devolve os dois limites.

### Tarefa 2: CSP
- [ ] Testes: toda resposta tem `Content-Security-Policy` com nonce em `script-src`, `frame-ancestors 'none'` e `object-src 'none'`; o nonce aparece nas tags de script da página e muda a cada requisição; em produção, sem as origens do Vite.
- [ ] `SecurityHeaders` monta a política; `Vite::useCspNonce()` gera o nonce.
- [ ] Conferir no navegador que todas as páginas funcionam sem erro de CSP no console (login, 2FA, Visão Geral, Assinaturas, Remetentes, Regras, Contas, Segurança com QR code, públicas).

### Tarefa 3: nginx
- [ ] `deploy/nginx.conf.template`: servidores padrão nas portas 80 e 443.
- [ ] `DeployFilesTest`: o template tem os dois `default_server` e `ssl_reject_handshake on`.
- [ ] Conferir com `nginx -t` num container com o template renderizado.
- [ ] README (Produção): como aplicar na VPS.

### Final
- [ ] Todas as verificações do projeto; PR; CI; merge.
