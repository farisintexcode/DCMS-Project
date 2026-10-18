<?php

namespace App\Policies;

use App\Models\CommunicationTemplate;
use App\Models\User;

class CommunicationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('communications.view') && ($user->can('templates.manage') || $user->can('templates.approve') || $user->can('communications.compose'));
    }

    public function view(User $user, CommunicationTemplate $template): bool
    {
        return $this->viewAny($user) && $template->channel === 'email';
    }

    public function update(User $user, CommunicationTemplate $template): bool
    {
        return $this->view($user, $template) && $user->can('templates.manage') && $template->status === 'draft';
    }

    public function submit(User $user, CommunicationTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function createVersion(User $user, CommunicationTemplate $template): bool
    {
        return $this->view($user, $template) && $user->can('templates.manage') && in_array($template->status, ['approved', 'rejected', 'archived'], true);
    }

    public function approve(User $user, CommunicationTemplate $template): bool
    {
        return $this->view($user, $template) && $user->can('templates.approve') && $template->status === 'pending_approval';
    }

    public function reject(User $user, CommunicationTemplate $template): bool
    {
        return $this->approve($user, $template);
    }

    public function archive(User $user, CommunicationTemplate $template): bool
    {
        return $this->view($user, $template) && $user->can('templates.approve') && $template->status === 'approved';
    }
}
