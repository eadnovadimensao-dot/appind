<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acesso não permitido</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: system-ui, -apple-system, sans-serif;
    background: #f0f0f0;
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
  }
  .card {
    background: white; border-radius: 16px;
    padding: 40px 32px; max-width: 420px; width: 100%;
    text-align: center;
  }
  .icon { font-size: 48px; margin-bottom: 16px; }
  h1 { font-size: 18px; font-weight: 600; color: #1a2332; margin-bottom: 8px; }
  p { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 24px; }
  .btn {
    display: inline-block; padding: 12px 24px; border-radius: 10px;
    background: #1D9E75; color: white; text-decoration: none;
    font-size: 14px; font-weight: 500;
  }
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔒</div>
  <h1>Acesso não permitido</h1>
  <p>Você não tem permissão para acessar esta página ou realizar esta ação.</p>
  <a href="/dashboard.php" class="btn">Voltar ao início</a>
</div>
</body>
</html>
