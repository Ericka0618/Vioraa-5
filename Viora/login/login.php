<?php
session_start();

// Si ya está logueado, lo mando al dashboard
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: principal.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VIORA - Administración de Condominios</title>

  <!-- Enlazando el CSS externo -->
  <link rel="stylesheet" href="../estilos/login.css">
</head>
<body>
  <div class="login-container">
    <!-- Panel Izquierdo con imagen y eslogan -->
    <div class="info-panel">
      <div class="brand-logo-top">VIORA</div>
      <h2>Tu propiedad,<br>en buenas manos</h2>
    </div>

    <!-- Panel Derecho con formulario -->
    <div class="login-panel">
      <div class="brand-title">VIORA</div>
      <div class="welcome-title">Bienvenido de nuevo</div>
      <div class="welcome-subtitle">Accede a tu espacio y gestiona tus propiedades fácilmente</div>

      <div id="errorMessage" class="error-message"></div>

      <!-- Apunta a procesar_login.php -->
      <form id="loginForm" action="procesar_login.php" method="post" novalidate>
        <div class="form-group">
          <label for="username">Usuario</label>
          <div class="input-with-icon">
            <i class="fa-regular fa-envelope input-icon"></i>
            <input type="text" id="username" name="username" placeholder="Nombre de usuario" required>
          </div>
        </div>

        <div class="form-group">
          <label for="password">Contraseña</label>
          <div class="input-with-icon">
            <i class="fa-regular fa-lock input-icon"></i>
            <input type="password" id="password" name="password" placeholder="Introduce tu contraseña" required>
          </div>
        </div>

        <div class="form-options">
          <label class="remember-me">
            <input type="checkbox" name="remember"> Recordarme
          </label>
          <a href="#" class="forgot-password">¿Olvidaste tu contraseña?</a>
        </div>

        <button type="submit" class="btn-login">Iniciar Sesión</button>
      </form>

      <div class="support-link">
        ¿Nuevo usuario?  <a href="../login/registro.php">Regístrate</a>
      </div>
    </div>
  </div>

<script>
  // Mostrar mensajes desde ?error=
  (function () {
    const params = new URLSearchParams(window.location.search);
    const e = params.get('error');
    const box = document.getElementById('errorMessage');
    if (!e) return;

    const map = {
      empty_fields: 'Por favor, complete todos los campos.',
      invalid_credentials: 'Usuario o contraseña incorrectos.',
      inactive: 'Tu usuario está inactivo. Contacta al administrador.',
      method_not_allowed: 'Método no permitido.',
      server_error: 'Ocurrió un error. Intente nuevamente.'
    };

    box.textContent = map[e] || 'Ocurrió un error.';
    box.style.display = 'block';
  })();

  // Validación sencilla
  document.getElementById('loginForm').addEventListener('submit', function(e) {
    const user = document.getElementById('username').value.trim();
    const pass = document.getElementById('password').value;
    const box = document.getElementById('errorMessage');

    if (!user || !pass) {
      e.preventDefault();
      box.textContent = 'Por favor, complete todos los campos.';
      box.style.display = 'block';
    }
  });
</script>

</body>
</html>