<?php

namespace App\Libraries;

use ArrayAccess;

/**
 * Riga di documento leggibile sia come array sia come oggetto.
 *
 * I due fogli PDF non erano d'accordo: quello della fattura legge
 * $invoice->numero, quello del preventivo $quote['numero']. Invece di
 * riscrivere uno dei due template (e con esso l'impaginazione gia' verificata),
 * la composizione passa qui i dati: questa classe risponde a entrambe le
 * sintassi, cosi' la logica del PDF e' una sola.
 *
 * @implements ArrayAccess<string, mixed>
 */
class DocumentRow implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Avvolge array, oggetti e valori gia' avvolti; lascia passare gli altri
     * tipi (null, false, scalari) perche' le viste li controllano cosi'.
     */
    public static function wrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_object($value)) {
            return new self((array) $value);
        }

        if (is_array($value)) {
            return new self($value);
        }

        return $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    /** Dati originali, per chi deve passarli altrove (es. json_encode). */
    public function toArray(): array
    {
        return $this->data;
    }
}
