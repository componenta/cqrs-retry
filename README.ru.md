# Componenta CQRS Retry

Синхронные повторы CQRS-команд: ограничение числа попыток, задержка и нативное PHP-условие в `#[Retry]`.

[English documentation](README.md)

## Требования и регистрация

Ветка v4 требует PHP **8.5.13+**, Componenta CQRS **5** и Componenta Config **4**.

```bash
composer require componenta/cqrs-retry
```

Зарегистрируйте провайдеры и добавьте `Componenta\CQRS\Command\Middleware\RetryMiddleware` в цепочку middleware команд:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Retry\ConfigProvider(),
];
```

Фабрика использует CQRS `CommandMetadataProviderInterface`. Стандартный провайдер кеширует reflection-дескрипторы и создает новый экземпляр атрибута. Middleware читает метаданные один раз при входе и не кеширует условие или решение между вызовами.

## Стандартное правило повторов

```php
use Componenta\CQRS\Retry\Attribute\Retry;

#[Retry(attempts: 3, delayMs: 100, multiplier: 2.0, maxDelayMs: 1000)]
final readonly class RefreshRecord
{
    public function __construct(public string $recordId) {}
}
```

При `when: null` повтор разрешен, если throwable реализует `Componenta\CQRS\Command\Exception\RetryableExceptionInterface` или соответствует классу/интерфейсу из `retryableExceptions` middleware. Команда без `Retry` выполняется один раз.

`attempts` считает **все вызовы обработчика, включая первый**. На последней неудаче middleware выбрасывает тот же throwable без нового решения и ожидания. При успехе возвращается ровно та операция, которую вернул обработчик.

## Условие команды

Явный `when` **заменяет** стандартную классификацию. Он может разрешить повтор для ошибки вне списка либо запретить повтор для marker/configured-ошибки. Ограничение попыток проверяется первым.

```php
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Retry\Attribute\Retry;

final class TemporaryReadFailure extends RuntimeException {}

#[Retry(attempts: 3, when: self::mayRepeat(...))]
final readonly class RefreshExternalRecord
{
    public function __construct(public string $recordId) {}

    private static function mayRepeat(
        Throwable $failure,
        OperationInterface $operation,
        int $failedAttempt,
    ): bool {
        return $failure instanceof TemporaryReadFailure
            && $operation->command instanceof self;
    }
}
```

Контракт callback — `Closure(Throwable, OperationInterface, int): bool`:

- Первый аргумент — исходный throwable только что завершившейся попытки.
- Второй — исходная операция этого вызова middleware, включая команду и атрибуты.
- Третий — номер неудачной попытки, начиная с единицы; после первого вызова это `1`.

Условие выполняется один раз после неудачной попытки, только если осталась следующая, до jitter и ожидания. Оно не выполняется при чтении метаданных, после успеха и после последней допустимой неудачи. `false` выбрасывает исходную ошибку без ожидания, `true` запускает обычный планировщик задержки. Возврата к стандартному классификатору нет.

Результат должен быть именно boolean. Число, строка, null и другие truthy/falsy-значения недопустимы. Переприсваивание аргумента по ссылке не заменяет исходные failure, operation или счетчик попыток. Общие объекты остаются изменяемыми по обычным правилам PHP.

Для inline-условия используйте `static function` без захвата переменных:

```php
#[Retry(when: static function (Throwable $failure, OperationInterface $operation, int $failedAttempt): bool {
    return $failure instanceof TemporaryReadFailure;
})]
final readonly class RefreshSnapshot {}
```

First-class callable функций и статических методов сохраняют нативную область видимости, включая приватные методы. `fn` и `static fn` недопустимы в константных выражениях PHP 8.5. Минимум **PHP 8.5.13** учитывает исправление scope и visibility callable в trait. Программное создание атрибута принимает `?Closure`, без поиска callback в контейнере и autowiring аргументов.

## Ошибка условия

Исключение callback, несовместимая сигнатура или не-boolean результат приводят к `Componenta\CQRS\Command\Exception\RetryConditionException`:

| Поле | Значение |
|---|---|
| `primaryFailure` | Исходный throwable обработчика. |
| `conditionFailure` | Throwable callback либо `TypeError` для неверного нетипизированного результата. |
| `getPrevious()` | Исходный throwable обработчика. |

Сообщение указывает номер попытки и boolean-контракт, не копируя данные команды и сообщения исходных ошибок. Ошибка условия завершает этот вызов middleware: без fallback, jitter, ожидания и нового вызова обработчика. Ошибки метаданных сохраняют свой тип. Ошибки sleep/random-зависимостей остаются ошибками планирования и не классифицируются заново.

## Задержка и идентичность операции

| Аргумент | По умолчанию | Контракт |
|---|---|---|
| `attempts` | `3` | Хотя бы один общий вызов обработчика. |
| `delayMs` | `100` | Начальная неотрицательная задержка в миллисекундах. |
| `multiplier` | `1.0` | Конечный множитель не меньше единицы. |
| `maxDelayMs` | `10000` | Предел не меньше `delayMs`, безопасный для перевода в микросекунды. |
| `when` | `null` | Необязательный заменяющий классификатор. |

Опция middleware `jitter` по умолчанию равна `true`: добавляет до ±25% в пределах максимальной задержки. При нулевой задержке ожидание и генератор случайных чисел не вызываются. Конструктор принимает `sleep(int $milliseconds): void` и `randomInt(int $min, int $max): int`; по умолчанию используются `usleep()` и `random_int()`. Зарегистрированная фабрика оставляет эти значения.

Каждая попытка получает исходную операцию. Неизменяемость поверхностная: изменения команды, внешние I/O и транзакционные эффекты не отменяются. Приложение отвечает за допустимость повторного выполнения. Автоматический idempotency key и сохранение состояния повторов не добавляются.

## Порядок middleware

Retry снаружи transaction middleware повторно входит в transaction middleware на каждой попытке. Transaction снаружи retry оставляет попытки внутри общей транзакции.

Lock снаружи retry удерживает одну блокировку на все попытки. Retry снаружи lock вычисляет ключ, захватывает и освобождает ресурс на каждой попытке. Условие меняет только допустимость повторов, не вставляя и не переставляя middleware.

## Разработка

Проверки: `composer test` и `composer analyse`. [Спецификация](docs/specifications/attribute-callbacks.md) содержит критерии приемки, [проверка интеграции](docs/development/integration.md) — ревизии зависимостей и воспроизводимую настройку окружения.
