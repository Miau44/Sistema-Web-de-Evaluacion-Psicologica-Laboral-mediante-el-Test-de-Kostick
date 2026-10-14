<?php require_once __DIR__ . '/../includes/view.php'; ?>
<html>
<head>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
h1 { text-align:center; font-size:18px; margin-bottom:10px; }
.section-title { font-weight:bold; margin-top:20px; border-bottom:1px solid #444; }
.table { width:100%; border-collapse:collapse; margin-top:10px; }
.table th, .table td { border:1px solid #aaa; padding:6px; }
</style>
</head>
<body>

<?php $nombreMostrado = $nombreCompleto ?? $nombre ?? ''; ?>

<h1>INFORME – TEST DE KOSTICK</h1>

<p><strong>Nombre completo:</strong> <?= e($nombreMostrado) ?></p>
<p><strong>Edad:</strong> <?= e($edad) ?></p>
<p><strong>Profesión:</strong> <?= $profesion ?></p>
<p><strong>Fecha:</strong> <?= date('d/m/Y') ?></p>

<div class="section-title">Resumen General</div>
<p>
El presente informe resume de manera ejecutiva los resultados obtenidos en el Test de Kostick, 
evaluando rasgos de productividad, estilo de trabajo, adaptación y dinámicas socioemocionales.
</p>

<div class="section-title">Resultados por Dimensión</div>

<table class="table">
<tr><th>Dimensión</th><th>Puntaje</th><th>Interpretación</th></tr>

<tr>
<td>Grado de Energía</td>
<td><?= e($energia) ?></td>
<td><?= e(interpretacion($energia)) ?></td>
</tr>

<tr>
<td>Liderazgo</td>
<td><?= e($liderazgo) ?></td>
<td><?= e(interpretacion($liderazgo)) ?></td>
</tr>

<!-- Agregar el resto -->
</table>

<div class="section-title">Conclusiones</div>
<p>
En general, el evaluado muestra un perfil <?= e(resumen_final($scores)) ?>.
</p>

</body>
</html>
