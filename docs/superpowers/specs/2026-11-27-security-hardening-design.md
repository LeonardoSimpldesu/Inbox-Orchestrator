# Design: Proteções de segurança restantes

Status: aprovado · Data: 2026-11-27

## Intenção
Fechar as três recomendações que ficaram de fora da revisão de segurança (PR #10) antes da próxima tag.

## Decisões
1. **Login limitado por e-mail somando todos os IPs (opção A, escolhida pelo dono):** além de 5 por minuto por e-mail + IP, no máximo 20 tentativas por hora por e-mail. Custo aceito: quem souber o e-mail consegue bloquear o login dele por até 1 hora.
2. **nginx:** servidor padrão que recusa qualquer domínio que não seja o do app (porta 80: fecha a conexão com 444; porta 443: `ssl_reject_handshake on`, sem certificado). Aplicado na VPS por comando do dono, porque o deploy por tag não mexe no nginx.
3. **CSP**, enviada pelo app em toda resposta:
   - scripts só do próprio app, com nonce por requisição (`Vite::useCspNonce`);
   - estilos do app, `'unsafe-inline'` (React e o Inertia usam atributos `style`) e Google Fonts; fontes do app e de `fonts.gstatic.com`;
   - imagens do app e `data:`; conexões só com o app;
   - `frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`, `object-src 'none'`;
   - em desenvolvimento, o servidor do Vite (script, estilo e websocket) é liberado.

## Não-objetivos
- Hospedar as fontes no próprio app (o Google Fonts continua).
- 2FA obrigatório.
