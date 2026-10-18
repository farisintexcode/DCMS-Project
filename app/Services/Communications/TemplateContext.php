<?php

namespace App\Services\Communications;

use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** UI65/UI67 context: existing scoped identity, no invented financial facts. */
class TemplateContext
{
    public function __construct(private ClientAccess $clients) {}

    private function authorize(User $actor): void
    {
        abort_unless($actor->isActive() && $actor->can('admin.access') && $actor->can('communications.compose') && $actor->can('communications.view') && $actor->can('customers.view'), 403);
    }

    public function find(User $actor, string $reference): Debtor
    {
        $this->authorize($actor);
        abort_if(trim($reference) === '' || mb_strlen($reference) > 100, 404);

        return $this->clients->scope(Debtor::query(), $actor)->with('client')->where('customer_reference', trim($reference))->sole();
    }

    public function customer(User $actor, string $publicId): Debtor
    {
        $this->authorize($actor);
        abort_unless(Str::isUuid($publicId), 404);

        return $this->clients->scope(Debtor::query(), $actor)->with('client')->where('public_id', $publicId)->firstOrFail();
    }

    public function values(User $actor, string $customerId, string $accountId = '', string $taskId = ''): array
    {
        $customer = $this->customer($actor, $customerId);
        $account = null;
        $task = null;
        if ($taskId !== '') {
            abort_unless(Str::isUuid($taskId), 404);
            $task = Task::query()->where('customer_id', $customer->id)->where('public_id', $taskId)->with(['batch.client', 'account'])->firstOrFail();
            Gate::forUser($actor)->authorize('view', $task);
            $account = $task->account;
        } elseif ($accountId !== '') {
            abort_unless(Str::isUuid($accountId), 404);
            $account = DebtAccount::query()->where('client_id', $customer->client_id)->where('public_id', $accountId)->whereHas('parties', fn ($q) => $q->where('debtor_id', $customer->id)->whereNull('valid_to'))->with('client')->firstOrFail();
            Gate::forUser($actor)->authorize('view', $account);
        }

        return ['customer' => $customer, 'account' => $account, 'task' => $task, 'values' => ['customer_name' => $customer->display_name_ciphertext, 'company_name' => $customer->client->name, 'account_number' => $account?->account_number, 'outstanding_amount' => null, 'due_date' => null],
            'sources' => ['customer_name' => 'Current customer identity', 'company_name' => 'Commissioning client', 'account_number' => $account ? 'Selected linked account' : 'No account selected', 'outstanding_amount' => 'No approved financial snapshot available', 'due_date' => 'No approved payment due date available; task scheduling is not used']];
    }

    public function accounts(Debtor $customer): Builder
    {
        return DebtAccount::query()->where('client_id', $customer->client_id)->whereHas('parties', fn ($q) => $q->where('debtor_id', $customer->id)->whereNull('valid_to'))->orderBy('account_number')->orderBy('id');
    }
}
