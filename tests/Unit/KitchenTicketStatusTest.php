<?php

declare(strict_types=1);

use App\Domains\Kitchen\Enums\KitchenTicketStatus;

it('labels the three states in spanish', function (): void {
    expect(KitchenTicketStatus::Pending->getLabel())->toBe('Pendiente')
        ->and(KitchenTicketStatus::InProgress->getLabel())->toBe('En proceso')
        ->and(KitchenTicketStatus::Ready->getLabel())->toBe('Lista');
});

it('has exactly three states', function (): void {
    // El día que alguien añada «Entregada» o «Cancelada», este test lo dice.
    expect(array_map(
        fn (KitchenTicketStatus $estado): string => $estado->value,
        KitchenTicketStatus::cases(),
    ))->toBe(['pending', 'in_progress', 'ready']);
});

it('coerces from the stored string', function (): void {
    expect(KitchenTicketStatus::coerce('in_progress'))->toBe(KitchenTicketStatus::InProgress)
        ->and(KitchenTicketStatus::coerce(KitchenTicketStatus::Ready))->toBe(KitchenTicketStatus::Ready);
});

it('rejects a value that is not one of the three', function (): void {
    KitchenTicketStatus::coerce('delivered');
})->throws(ValueError::class);

it('counts pending and in progress as open work', function (): void {
    expect(KitchenTicketStatus::Pending->isOpen())->toBeTrue()
        ->and(KitchenTicketStatus::InProgress->isOpen())->toBeTrue()
        ->and(KitchenTicketStatus::Ready->isOpen())->toBeFalse();
});

it('advances pending to in progress and in progress to ready', function (): void {
    expect(KitchenTicketStatus::Pending->next())->toBe(KitchenTicketStatus::InProgress)
        ->and(KitchenTicketStatus::InProgress->next())->toBe(KitchenTicketStatus::Ready);
});

it('has nothing after ready', function (): void {
    // Lista es terminal: no existe «Entregada» a la que avanzar.
    expect(KitchenTicketStatus::Ready->next())->toBeNull();
});

it('allows every forward transition, including the direct jump to ready', function (): void {
    // El salto directo es el caso de la cerveza: se sirve antes de que a
    // nadie le dé tiempo de marcarla en proceso.
    expect(KitchenTicketStatus::Pending->canTransitionTo(KitchenTicketStatus::InProgress))->toBeTrue()
        ->and(KitchenTicketStatus::Pending->canTransitionTo(KitchenTicketStatus::Ready))->toBeTrue()
        ->and(KitchenTicketStatus::InProgress->canTransitionTo(KitchenTicketStatus::Ready))->toBeTrue();
});

it('allows stepping one state back', function (): void {
    // Los toques equivocados existen y el tablero tiene que perdonarlos.
    expect(KitchenTicketStatus::Ready->canTransitionTo(KitchenTicketStatus::InProgress))->toBeTrue()
        ->and(KitchenTicketStatus::InProgress->canTransitionTo(KitchenTicketStatus::Pending))->toBeTrue();
});

it('refuses to walk two states back at once', function (): void {
    // Volver de Lista a Pendiente no es corregir un dedazo: es rehacer el
    // plato, y eso se hace con los dos toques a conciencia.
    expect(KitchenTicketStatus::Ready->canTransitionTo(KitchenTicketStatus::Pending))->toBeFalse();
});

it('refuses to transition a state into itself', function (KitchenTicketStatus $estado): void {
    // Sin esto, dos tablets tocando a la vez contarían el paso dos veces.
    expect($estado->canTransitionTo($estado))->toBeFalse();
})->with(KitchenTicketStatus::cases());

it('covers the whole transition matrix', function (): void {
    // La matriz entera escrita a mano: si alguien cambia una regla suelta
    // en canTransitionTo(), aquí se ve cuál y en qué dirección.
    $esperada = [
        'pending' => ['pending' => false, 'in_progress' => true, 'ready' => true],
        'in_progress' => ['pending' => true, 'in_progress' => false, 'ready' => true],
        'ready' => ['pending' => false, 'in_progress' => true, 'ready' => false],
    ];

    foreach (KitchenTicketStatus::cases() as $desde) {
        foreach (KitchenTicketStatus::cases() as $hasta) {
            expect($desde->canTransitionTo($hasta))->toBe(
                $esperada[$desde->value][$hasta->value],
                "Transición {$desde->value} → {$hasta->value}",
            );
        }
    }
});
