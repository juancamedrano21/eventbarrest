<?php

declare(strict_types=1);

namespace App\Domains\EventApp\Models;

use App\Domains\EventApp\Support\CacheDeRespuesta;
use App\Domains\EventApp\Support\UrlAlcanzable;
use App\Domains\EventManagement\Models\Event;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * La marca y los módulos de la app de un evento: lo que convierte un mismo
 * binario en la app de Bocao o en la de otro festival sin recompilar.
 *
 * TODO LO QUE HAY AQUÍ TIENE VALOR POR DEFECTO, y eso no es una comodidad:
 * es la diferencia entre que la app de un evento arranque o no. Un evento
 * recién creado no tiene fila en esta tabla —nadie ha entrado a configurar
 * los colores— y su app tiene que pintarse igual, con la marca sobria y el
 * único módulo que el servidor sabe servir. Por eso `paraEvento()` devuelve
 * una instancia SIN GUARDAR cuando no hay fila, en vez de null: quien pinta
 * el manifiesto no tiene que saber si alguien lo configuró.
 *
 * Y por eso los defectos viven aquí y no como DEFAULT de la base. Dos sitios
 * con el mismo valor se separan el día que alguien cambia uno, y entonces un
 * evento configurado y otro sin configurar dejan de parecerse sin que nadie
 * lo haya decidido. Nulo en la columna significa «no lo ha tocado nadie»,
 * que es información distinta de «lo puso igual al defecto».
 *
 * @property int $id
 * @property int $event_id
 * @property string|null $app_name
 * @property string|null $logo_path
 * @property string|null $primary_color
 * @property string|null $accent_color
 * @property string|null $background_color
 * @property string|null $surface_color
 * @property string|null $text_color
 * @property string|null $heading_font
 * @property string|null $body_font
 * @property array<array-key, mixed>|null $modules
 * @property array<array-key, mixed>|null $texts Las claves se declaran array-key y no string: vienen de json_decode, y `{"0": "hola"}` entra aquí como entero
 */
class EventAppManifest extends Model
{
    use BelongsToTenant;

    /**
     * La marca sobria de fábrica: gris casi negro sobre blanco. No pretende
     * ser bonita, pretende ser LEGIBLE en un teléfono al sol de las cuatro
     * de la tarde mientras alguien busca dónde comer. Un evento sin
     * configurar no tiene por qué parecerse a otro; tiene que leerse.
     */
    private const COLORES = [
        'primary_color' => '#1A1A1A',
        'accent_color' => '#4B5563',
        'background_color' => '#FFFFFF',
        'surface_color' => '#F5F5F5',
        'text_color' => '#1A1A1A',
    ];

    /**
     * Menús y nada más, porque es lo único que este slice sabe servir. Un
     * manifiesto de fábrica que encendiera módulos sin endpoint detrás sería
     * una app que promete pantallas vacías, y el contrato dice que lo que
     * manda es esta lista.
     */
    private const MODULOS_POR_DEFECTO = [
        ['clave' => 'menus', 'titulo' => 'Menús', 'orden' => 1, 'activo' => true],
    ];

    protected $table = 'event_app_manifests';

    protected $fillable = [
        'event_id',
        'app_name',
        'logo_path',
        'primary_color',
        'accent_color',
        'background_color',
        'surface_color',
        'text_color',
        'heading_font',
        'body_font',
        'modules',
        'texts',
    ];

    /**
     * El manifiesto del evento, configurado o no. Nunca devuelve null: un
     * evento sin fila recibe una instancia en blanco, que sabe contestar a
     * todo con su valor por defecto.
     */
    public static function paraEvento(Event $event): self
    {
        return self::query()->where('event_id', $event->id)->first() ?? new self;
    }

    /**
     * El bloque `marca` del contrato, ya resuelto: sin nulos donde el
     * contrato promete un color, y con el nombre del evento cuando nadie ha
     * escrito uno propio.
     *
     * @return array<string, string|null>
     */
    public function marca(Event $event): array
    {
        return [
            'nombre_app' => $this->texto($this->app_name) ?? $event->name,
            'color_primario' => $this->color('primary_color'),
            'color_acento' => $this->color('accent_color'),
            'color_fondo' => $this->color('background_color'),
            'color_superficie' => $this->color('surface_color'),
            'color_texto' => $this->color('text_color'),
            'logo_url' => $this->logoUrl(),
            // Las fuentes SÍ pueden viajar nulas: la app tiene la suya y una
            // fuente inventada por el servidor no existiría en el teléfono.
            'fuente_titulos' => $this->texto($this->heading_font),
            'fuente_texto' => $this->texto($this->body_font),
        ];
    }

    /**
     * La lista de módulos, saneada y ordenada.
     *
     * Se publican TAMBIÉN los apagados, con su `activo: false`, porque el
     * contrato dice que la lista manda: la app pinta lo que está encendido y
     * el resto le sirve para saber que existe. Y una entrada rota —sin clave,
     * sin título, con el orden en texto— se cae en silencio en vez de viajar:
     * este JSON lo escribirá un formulario del panel, y un teclazo ahí no
     * puede ser una app que no arranca.
     *
     * ESO VALE TAMBIÉN CUANDO LO ROTO ES LA LISTA ENTERA. La columna es JSON
     * libre, así que un import o un UPDATE a mano pueden dejar ahí un escalar
     * —`"menus"` en vez de `["menus"]`—, y recorrerlo sería un 500 en el
     * ÚNICO endpoint sin el cual la app no puede pintarse: un manifiesto que
     * alguien corrompió apagaría la app de ese festival entero. Un contenedor
     * que no es lista vale lo mismo que no haber tocado nada: se sirve lo de
     * fábrica. Nulo y basura se tratan igual a propósito, porque la
     * diferencia entre los dos no la puede aprovechar un teléfono.
     *
     * @return array<int, array<string, mixed>>
     */
    public function modulos(): array
    {
        // Por getAttribute y no por la propiedad: el cast dice `array`, pero
        // lo que sale de json_decode es lo que haya en la columna.
        $crudos = $this->getAttribute('modules');

        if (! is_array($crudos)) {
            $crudos = self::MODULOS_POR_DEFECTO;
        }

        $limpios = [];

        foreach ($crudos as $modulo) {
            if (! is_array($modulo)) {
                continue;
            }

            $clave = $this->texto($modulo['clave'] ?? null);
            $titulo = $this->texto($modulo['titulo'] ?? null);
            $orden = filter_var($modulo['orden'] ?? null, FILTER_VALIDATE_INT);

            if ($clave === null || $titulo === null || $orden === false) {
                continue;
            }

            $limpios[] = [
                'clave' => $clave,
                'titulo' => $titulo,
                'orden' => $orden,
                'activo' => (bool) ($modulo['activo'] ?? false),
            ];
        }

        // Ordenado aquí y no en la app: el contrato promete un orden, y
        // dejar que lo calcule cada cliente es dejar que dos versiones de la
        // app enseñen el menú en sitios distintos. El desempate por clave
        // hace la respuesta estable, que es lo que sostiene el ETag.
        usort($limpios, fn (array $a, array $b): int => [$a['orden'], $a['clave']] <=> [$b['orden'], $b['clave']]);

        return $limpios;
    }

    /**
     * Los textos de marca, siempre como diccionario.
     *
     * Mismo trato que los módulos si la columna guarda algo que no es un
     * mapa: diccionario vacío y la app arranca con los suyos, en vez de un
     * 500 que la deja sin manifiesto.
     *
     * @return array<string, string>
     */
    public function textos(): array
    {
        $crudos = $this->getAttribute('texts');

        if (! is_array($crudos)) {
            return [];
        }

        $textos = [];

        foreach ($crudos as $clave => $valor) {
            $texto = $this->texto($valor);

            if (is_string($clave) && $clave !== '' && $texto !== null) {
                $textos[$clave] = $texto;
            }
        }

        return $textos;
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * El manifiesto es lo ÚNICO que tira la caché de su endpoint al escribirse,
     * y por qué merece esa excepción: es la única de las tres respuestas que
     * alguien cambia MIRANDO el resultado. Se elige un color en el panel, se
     * mira el teléfono, y si no ha cambiado la conclusión no es «hay una caché
     * de diez segundos», es «el panel no guardó» — y detrás de esa conclusión
     * viene guardar tres veces más. El catálogo no tiene ese problema: nadie
     * desactiva un plato con la app del asistente abierta al lado.
     *
     * Colgado del modelo y no de una acción a propósito. El formulario del
     * panel todavía no existe, así que hoy quien escribe aquí es un seeder o
     * una consola; cuando exista, la invalidación ya estará puesta sin que
     * nadie tenga que acordarse de llamarla. Lo que se salta esto es un UPDATE
     * por query builder, que no dispara eventos de modelo — y eso está bien:
     * quien escribe por debajo del modelo se salta también los casts y ya sabe
     * lo que hace.
     */
    protected static function booted(): void
    {
        $olvidar = function (self $manifiesto): void {
            CacheDeRespuesta::olvidar(CacheDeRespuesta::MANIFIESTO, $manifiesto->event_id);
        };

        static::saved($olvidar);
        static::deleted($olvidar);
    }

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'texts' => 'array',
        ];
    }

    /**
     * Un color del contrato: hexadecimal de seis dígitos con almohadilla, o
     * el de fábrica.
     *
     * Se valida al PUBLICAR y no solo al guardar. La app promete no reventar
     * por un color, pero eso es su red, no una excusa para mandarle basura:
     * un `rgb(0,0,0)` metido por un import o por SQL a mano saldría de aquí
     * como color y dejaría la pantalla de ese evento sin contraste, que es
     * un fallo mudo y solo visible en el teléfono de otro.
     */
    private function color(string $columna): string
    {
        $valor = $this->texto($this->getAttribute($columna));

        return $valor !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $valor) === 1
            ? mb_strtoupper($valor)
            : self::COLORES[$columna];
    }

    private function logoUrl(): ?string
    {
        $ruta = $this->texto($this->logo_path);

        // Absoluta siempre: al otro lado hay un teléfono en datos móviles,
        // no un navegador que sepa completar una ruta relativa con el origen
        // desde el que se descargó la página.
        return $ruta === null ? null : UrlAlcanzable::desde(Storage::disk('public')->url($ruta));
    }

    /** El valor si es texto con contenido; null en cualquier otro caso. */
    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }
}
