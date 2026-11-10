<?php
session_start();

if (empty($_SESSION['registro_pendiente_usuario'])) {
    header("Location: registro.php");
    exit;
}

// Credenciales públicas de PayPal Sandbox
$paypalClientId = getenv('PAYPAL_CLIENT_ID') ?: '';

$paypalPlanId = getenv('PAYPAL_PLAN_ID') ?: '';

// Comprobar que los identificadores estén configurados
$configurado =
    !empty($paypalClientId) &&
    !empty($paypalPlanId) &&
    $paypalClientId !== 'TU_SANDBOX_CLIENT_ID' &&
    $paypalPlanId !== 'TU_SANDBOX_PLAN_ID';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Activar suscripción | VIORA</title>

    <link rel="stylesheet" href="../estilos/login.css">

    <style>
        body {
            padding: 20px;
        }

        .login-container {
            width: 1050px;
            height: auto;
            min-height: 560px;
        }

        .login-panel {
            padding: 45px 50px;
        }

        .plan-card {
            border: 1px solid #dce5df;
            border-radius: 10px;
            padding: 24px;
            margin: 15px 0 22px;
        }

        .plan-title {
            font-size: 19px;
            color: #0F382B;
        }

        .plan-price {
            font-size: 36px;
            font-weight: bold;
            margin: 15px 0 5px;
        }

        .plan-price span {
            font-size: 15px;
            color: #777;
            font-weight: normal;
        }

        .plan-description {
            color: #777;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .benefits {
            list-style: none;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }

        .benefits li {
            margin-bottom: 13px;
            font-size: 14px;
        }

        .benefits li::before {
            content: "✓";
            color: #16814d;
            margin-right: 10px;
            font-weight: bold;
        }

        .paypal-area {
            margin-top: 15px;
            min-height: 50px;
        }

        .payment-message {
            font-size: 13px;
            text-align: center;
            margin-top: 15px;
            line-height: 1.5;
        }

        .payment-error {
            color: #b42318;
        }

        .payment-success {
            color: #167347;
        }

        .security-note {
            font-size: 12px;
            color: #777;
            text-align: center;
            margin-top: 15px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                width: 100%;
            }

            .info-panel {
                min-height: 200px;
            }

            .login-panel {
                padding: 30px;
            }
        }
    </style>
</head>

<body>

<div class="login-container">

    <div class="info-panel">

        <div class="brand-logo-top">VIORA</div>

        <div class="info-content">
            <h2>
                Tu propiedad,<br>
                en buenas manos
            </h2>

            <p>
                Activa tu suscripción y comienza
                a disfrutar de nuestra plataforma.
            </p>
        </div>

    </div>

    <div class="login-panel">

        <div class="brand-title">VIORA</div>

        <h1 class="welcome-title">
            Activa tu cuenta
        </h1>

        <p class="welcome-subtitle">
            Tu registro fue completado.
            Elige tu suscripción para continuar.
        </p>

        <div class="plan-card">

            <h2 class="plan-title">
                Plan VIORA Mensual
            </h2>

            <div class="plan-price">
                US$10.00 <span>/ mes</span>
            </div>

            <p class="plan-description">
                Renovación automática mensual.
            </p>

            <ul class="benefits">
                <li>Acceso a la plataforma VIORA</li>
                <li>Explorar propiedades disponibles</li>
                <li>Consultar apartamentos en venta</li>
                <li>Consultar apartamentos en alquiler</li>
                <li>Gestionar solicitudes</li>
            </ul>

        </div>

        <div id="paypal-button-container"
             class="paypal-area"></div>

        <p id="payment-message"
           class="payment-message"
           role="status"
           aria-live="polite"></p>

        <p class="security-note">
            Pago de prueba mediante PayPal Sandbox.
            Tu cuenta permanecerá pendiente hasta
            que VIORA verifique la suscripción.
        </p>

    </div>

</div>

<?php if ($configurado): ?>

<script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode($paypalClientId) ?>&currency=USD&vault=true&intent=subscription&components=buttons"></script>

<script>
const mensaje = document.getElementById('payment-message');

paypal.Buttons({

    style: {
        shape: 'rect',
        color: 'gold',
        layout: 'vertical',
        label: 'subscribe'
    },

    createSubscription: function(data, actions) {
        return actions.subscription.create({
            plan_id: <?= json_encode($paypalPlanId) ?>,
            custom_id: <?= json_encode('viora-user-' . (int)$_SESSION['registro_pendiente_usuario']) ?>
        });
    },

    onApprove: async function(data) {
        mensaje.textContent =
            'Verificando tu suscripción con PayPal...';

        try {
            const respuesta = await fetch(
                'verificar_suscripcion.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        subscriptionID: data.subscriptionID
                    })
                }
            );

            const resultado = await respuesta.json();

            if (respuesta.ok && resultado.success) {
                mensaje.className =
                    'payment-message payment-success';

                mensaje.textContent =
                    'Suscripción verificada correctamente.';

                // Redirigir solo después de la
                // confirmación del servidor.
                window.location.href = '../login/login.php';
            } else {
                throw new Error(
                    resultado.message ||
                    'No se pudo verificar la suscripción.'
                );
            }

        } catch (error) {
            mensaje.className =
                'payment-message payment-error';

            mensaje.textContent = error.message;
        }
    },

    onCancel: function() {
        mensaje.textContent =
            'Cancelaste el proceso. Tu cuenta sigue pendiente.';
    },

    onError: function(error) {
        console.error('Error de PayPal:', error);

        mensaje.className =
            'payment-message payment-error';

        mensaje.textContent =
            'No fue posible completar el proceso con PayPal.';
    }

}).render('#paypal-button-container');
</script>

<?php else: ?>

<script>
document.getElementById('payment-message').textContent =
    'Falta configurar el Client ID y el Plan ID de PayPal Sandbox.';
</script>

<?php endif; ?>

</body>
</html>
