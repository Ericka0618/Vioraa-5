<?php
session_start();

if (empty($_SESSION['csrf_registro'])) {
    $_SESSION['csrf_registro'] = bin2hex(random_bytes(32));
}

$error = $_SESSION['registro_error'] ?? '';
unset($_SESSION['registro_error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrarse | VIORA</title>
     <link rel="stylesheet" href="../estilos/registro.css">
</head>
<body>

<div class="login-container">

    <!-- PANEL IZQUIERDO -->
    <div class="info-panel">

        <div class="brand-logo-top">VIORA</div>

        <div class="info-content">
            <h2>
                Tu propiedad,<br>
                en buenas manos
            </h2>

            <p>
                Crea tu cuenta y descubre una nueva
                forma de encontrar y gestionar
                tus propiedades.
            </p>
        </div>

    </div>

    <!-- PANEL DERECHO -->
    <div class="login-panel">

        <div class="brand-title">VIORA</div>

        <h1 class="welcome-title">Crear una cuenta</h1>

        <p class="welcome-subtitle">
            Completa tus datos para comenzar.
        </p>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <form action="guardar_registro.php" method="POST">

            <input type="hidden"
                   name="csrf"
                   value="<?= htmlspecialchars(
                       $_SESSION['csrf_registro'],
                       ENT_QUOTES,
                       'UTF-8'
                   ) ?>">

            <!-- NOMBRE Y APELLIDO -->
            <div class="form-row">

                <div class="form-group">
                    <label for="nombres">Nombres</label>

                    <div class="input-with-icon">
                        <span class="input-icon">♙</span>

                        <input type="text"
                               id="nombres"
                               name="nombres"
                               placeholder="Tus nombres"
                               maxlength="100"
                               autocomplete="given-name"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="apellidos">Apellidos</label>

                    <div class="input-with-icon">
                        <span class="input-icon">♙</span>

                        <input type="text"
                               id="apellidos"
                               name="apellidos"
                               placeholder="Tus apellidos"
                               maxlength="100"
                               autocomplete="family-name"
                               required>
                    </div>
                </div>

            </div>

            <!-- CEDULA Y TELEFONO -->
            <div class="form-row">

                <div class="form-group">
                    <label for="cedula">Cédula</label>

                    <div class="input-with-icon">
                        <span class="input-icon">▤</span>

                        <input type="text"
                               id="cedula"
                               name="cedula"
                               placeholder="000-0000000-0"
                               maxlength="13"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono</label>

                    <div class="input-with-icon">
                        <span class="input-icon">☎</span>

                        <input type="tel"
                               id="telefono"
                               name="telefono"
                               placeholder="809-000-0000"
                               maxlength="20"
                               autocomplete="tel"
                               required>
                    </div>
                </div>

            </div>

            <!-- CORREO -->
            <div class="form-group">
                <label for="correo">Correo electrónico</label>

                <div class="input-with-icon">
                    <span class="input-icon">✉</span>

                    <input type="email"
                           id="correo"
                           name="correo"
                           placeholder="ejemplo@correo.com"
                           maxlength="150"
                           autocomplete="email"
                           required>
                </div>
            </div>

            <!-- DIRECCION -->
            <div class="form-group">
                <label for="direccion">Dirección</label>

                <div class="input-with-icon">
                    <span class="input-icon">⌂</span>

                    <input type="text"
                           id="direccion"
                           name="direccion"
                           placeholder="Tu dirección"
                           autocomplete="street-address"
                           required>
                </div>
            </div>

            <!-- USUARIO -->
            <div class="form-group">
                <label for="usuario">Nombre de usuario</label>

                <div class="input-with-icon">
                    <span class="input-icon">♙</span>

                    <input type="text"
                           id="usuario"
                           name="usuario"
                           placeholder="Crea tu usuario"
                           maxlength="100"
                           autocomplete="username"
                           required>
                </div>
            </div>

            <!-- CONTRASEÑAS -->
            <div class="form-row">

                <div class="form-group">
                    <label for="clave">Contraseña</label>

                    <div class="input-with-icon">
                        <span class="input-icon">♢</span>

                        <input type="password"
                               id="clave"
                               name="clave"
                               placeholder="Contraseña"
                               minlength="8"
                               autocomplete="new-password"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirmar_clave">
                        Confirmar contraseña
                    </label>

                    <div class="input-with-icon">
                        <span class="input-icon">♢</span>

                        <input type="password"
                               id="confirmar_clave"
                               name="confirmar_clave"
                               placeholder="Confirmar"
                               minlength="8"
                               autocomplete="new-password"
                               required>
                    </div>
                </div>

            </div>

            <!-- TERMINOS -->
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox"
                           name="terminos"
                           required>

                    <span>
                        Acepto los términos y condiciones
                        de VIORA.
                    </span>
                </label>
            </div>

            <!-- BOTON -->
            <button type="submit" class="btn-login">
                Crear cuenta
            </button>

        </form>

        <p class="support-link">
            ¿Ya tienes una cuenta?
            <a href="../login/login.php">
                Iniciar sesión
            </a>
        </p>

        <p class="subscription-note">
            Después del registro podrás activar
            tu suscripción de forma segura.
        </p>

    </div>

</div>

</body>
</html>
