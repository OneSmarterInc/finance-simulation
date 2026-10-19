<?php

namespace App\Http\Controllers;

use App\Services\Tour\TourFigures;
use App\Support\Tour\AdopterTour;
use Illuminate\Contracts\View\View;

/**
 * Live walkthrough L8: the public adopter tour. No sign-in, nothing stored,
 * nothing calculated per visit: the figures are read from the tour file.
 */
class TourController extends Controller
{
    public function __invoke(int $step = 1): View
    {
        $figures = TourFigures::load();
        $steps = AdopterTour::steps($figures);
        abort_unless($step >= 1 && $step <= count($steps), 404);

        return view('tour.show', [
            'steps' => $steps,
            'step' => $step,
            'current' => $steps[$step - 1],
            'x' => $figures,
        ]);
    }
}
