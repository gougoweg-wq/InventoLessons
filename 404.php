<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>404 — AI AI AI SHIBAAAAAI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
  font-family: 'Montserrat', sans-serif;
  height: 100vh;
  margin: 0;
  display: flex;
  justify-content: center;
  align-items: center;
  overflow: hidden;
  position: relative;
  color: #ffd9f7;
  background-color: #000;
  background-image:
    linear-gradient(45deg, #fe00dc 25%, transparent 25%, transparent 75%, #fe00dc 75%, #fe00dc),
    linear-gradient(45deg, #fe00dc 25%, transparent 25%, transparent 75%, #fe00dc 75%, #fe00dc);
  background-size: 120px 120px;
  background-position: 0 0, 60px 60px;
  animation: glowShift 6s infinite alternate ease-in-out;
}


@keyframes glowShift {
  0%   { filter: brightness(1) saturate(1); }
  50%  { filter: brightness(1.3) saturate(1.4); }
  100% { filter: brightness(1) saturate(1); }
}

.card {
  background: rgba(0, 0, 0, 0.85);
  border: 2px solid rgba(254, 0, 220, 0.5);
  border-radius: 22px;
  padding: 50px;
  text-align: center;
  box-shadow: 0 0 45px rgba(254, 0, 220, 0.4);
  backdrop-filter: blur(6px);
  position: relative;
  z-index: 1;
  animation: floaty 3s ease-in-out infinite;
}
@keyframes floaty {
  0%,100% { transform: translateY(0); }
  50%     { transform: translateY(-10px); }
}


h1 {
  font-size: 120px;
  font-weight: 900;
  margin-bottom: 10px;
  background: linear-gradient(135deg, #fe00dc, #ff66e0, #ffb3f2);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  text-shadow: 0 0 30px rgba(254, 0, 220, 0.7);
}
h2 {
  font-weight: 700;
  color: #ff66e0;
  margin-bottom: 15px;
  text-shadow: 0 0 25px rgba(254, 0, 220, 0.8);
}
p {
  font-size: 18px;
  color: #ffd9f7;
}

.shiba {
  font-size: 90px;
  display: inline-block;
  margin: 25px 0;
  animation: spin 4s infinite linear;
  text-shadow: 0 0 25px rgba(254, 0, 220, 0.8);
}
@keyframes spin {
  0%   { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}


.btn-home {
  margin-top: 20px;
  background: linear-gradient(90deg, #fe00dc, #ff80eb);
  color: #fff;
  border: none;
  border-radius: 12px;
  padding: 12px 30px;
  font-weight: 600;
  transition: 0.3s;
  box-shadow: 0 0 25px rgba(254, 0, 220, 0.5);
}
.btn-home:hover {
  transform: scale(1.08);
  background: linear-gradient(90deg, #ff33ee, #ffa3f7);
  box-shadow: 0 0 40px rgba(254, 0, 220, 0.9);
}
</style>
</head>
<body>
<div class="card">
  <h1>404</h1>
  <h2>AI AI AI SHIBAAAAAI 🐕💥</h2>
  <p>ay ay ay它应该是一个耻辱
  <div class="shiba">🐕</div>
  <a href="index.php" class="btn-home">⬅️ Return to Base</a>
</div>
</body>
</html>
