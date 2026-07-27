<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Branches\Pages;

use App\Domains\Business\Actions\CreateBranch as CreateBranchAction;
use App\Domains\Operations\Enums\OperatingUnitKind;
use App\Domains\Operations\Enums\OperatingUnitStatus;
use App\Filament\App\Resources\Branches\BranchResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * La acción de dominio es quien comprueba que la cuenta sea de negocio:
     * un organizador nunca puede acabar con una sucursal suelta.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateBranchAction::class)(
            $data['name'],
            OperatingUnitKind::coerce($data['kind']),
            OperatingUnitStatus::coerce($data['status']),
        );
    }
}
