<?php

return [
    'reset' => 'Sua senha foi redefinida.',
    // Same message whether or not the e-mail exists (see PasswordResetLinkRequestedResponse).
    'sent' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.',
    'throttled' => 'Aguarde antes de tentar novamente.',
    'token' => 'Este link de redefinição de senha é inválido ou expirou.',
    // Only reached on the reset form (the reset-link request always answers with 'sent'):
    // the e-mail doesn't match the link's account, so the link itself is what's wrong.
    'user' => 'Este link de redefinição de senha é inválido ou expirou.',
];
