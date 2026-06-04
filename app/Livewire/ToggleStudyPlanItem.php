<?php

namespace App\Livewire;

use App\Models\StudyPlanItem;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ToggleStudyPlanItem extends Component
{
    public StudyPlanItem $item;

    public function toggle(): void
    {
        abort_unless($this->item->studyPlan->user_id === auth()->id(), 403);

        $this->item->toggleCompleted();
        $this->item->refresh();

        $this->dispatch('study-plan-item-toggled');
    }

    public function render(): View
    {
        return view('livewire.toggle-study-plan-item');
    }
}
