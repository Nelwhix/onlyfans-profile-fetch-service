---
paths:
  - 'app/Domain/*/Models/**'
---

# Models

## Non-App\Models factories need #[UseFactory]/#[UseModel]
Laravel's default factory discovery (HasFactory::factory()) only resolves App\Models\Foo -> Database\Factories\FooFactory; it silently breaks for models nested deeper (e.g. App\Domain\Profile\Models\Profile). Fix with Laravel 13's attributes: #[UseFactory(FooFactory::class)] on the model, #[UseModel(Foo::class)] on the factory. Do not use a global Factory::guessFactoryNamesUsing() override or the older protected $model property for this.
