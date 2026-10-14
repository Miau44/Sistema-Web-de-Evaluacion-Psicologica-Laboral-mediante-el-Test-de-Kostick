<?php
function calculate_scores($answers, $questions) {
    $scales = ['A','B','C','D','E','F','G','I','K','L','N','O','P','R','S','T','V','W','X','Z'];
    $scores = array_fill_keys($scales, 0);

    foreach ($questions as $q) {

        // Validar que la respuesta exista
        if (!isset($answers['q' . $q['id']])) {
            continue; // si falta, simplemente no suma
        }

        $choice = $answers['q' . $q['id']];

        if ($choice === 'A') {
            $scores[$q['escalaA']]++;
        } elseif ($choice === 'B') {
            $scores[$q['escalaB']]++;
        }
    }

    // NO limitar a 9 (cada escala llega a 9 naturalmente)
    // NO invertir Z ni K (el Kostick no usa inversión)

    // Calcular niveles (se mantiene porque ya estaba)
    $levels = [];
    foreach ($scores as $scale => $score) {
        if ($score <= 2) {
            $levels[$scale] = 'bajo';
        } elseif ($score <= 5) {
            $levels[$scale] = 'promedio';
        } else {
            $levels[$scale] = 'alto';
        }
    }

    return ['scores' => $scores, 'levels' => $levels];
}
?>
