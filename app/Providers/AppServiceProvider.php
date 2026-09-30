<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Complaint;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Tenancy;
use App\Observers\TenancyObserver;
use App\Policies\ComplaintPolicy;
use App\Policies\ContractPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\TenancyPolicy;
use App\Repositories\Contracts\BookingRepositoryInterface;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use App\Repositories\Contracts\ContractRepositoryInterface;
use App\Repositories\Contracts\FacilityRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\PropertyRepositoryInterface;
use App\Repositories\Contracts\RoomRepositoryInterface;
use App\Repositories\Contracts\TenancyRepositoryInterface;
use App\Repositories\Eloquent\BookingRepository;
use App\Repositories\Eloquent\ComplaintRepository;
use App\Repositories\Eloquent\ContractRepository;
use App\Repositories\Eloquent\FacilityRepository;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\PropertyRepository;
use App\Repositories\Eloquent\RoomRepository;
use App\Repositories\Eloquent\TenancyRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FacilityRepositoryInterface::class, FacilityRepository::class);
        $this->app->bind(PropertyRepositoryInterface::class, PropertyRepository::class);
        $this->app->bind(RoomRepositoryInterface::class, RoomRepository::class);
        $this->app->bind(TenancyRepositoryInterface::class, TenancyRepository::class);
        $this->app->bind(InvoiceRepositoryInterface::class, InvoiceRepository::class);
        $this->app->bind(BookingRepositoryInterface::class, BookingRepository::class);
        $this->app->bind(ComplaintRepositoryInterface::class, ComplaintRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(ContractRepositoryInterface::class, ContractRepository::class);
        $this->app->bind(
            \App\Repositories\Contracts\TenantDocumentRepositoryInterface::class,
            \App\Repositories\Eloquent\TenantDocumentRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\PropertyMediaRepositoryInterface::class,
            \App\Repositories\Eloquent\PropertyMediaRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\WaMessageRepositoryInterface::class,
            \App\Repositories\Eloquent\WaMessageRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\WaTemplateRepositoryInterface::class,
            \App\Repositories\Eloquent\WaTemplateRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\WaReminderRuleRepositoryInterface::class,
            \App\Repositories\Eloquent\WaReminderRuleRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\WaConversationRepositoryInterface::class,
            \App\Repositories\Eloquent\WaConversationRepository::class
        );
        $this->app->bind(
            \App\Contracts\WhatsAppProviderInterface::class,
            function () {
                $provider = config('services.whatsapp.provider', 'fake');
                if ($provider === 'fonnte') {
                    return new \App\Services\WhatsApp\FonnteProvider();
                }
                return new \App\Services\WhatsApp\FakeProvider();
            }
        );
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Tenancy::observe(TenancyObserver::class);

        Gate::policy(Tenancy::class, TenancyPolicy::class);
        Gate::policy(Complaint::class, ComplaintPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
    }
}
