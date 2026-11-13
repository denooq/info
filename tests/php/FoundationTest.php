<?php
declare(strict_types=1);

test('API errors expose a stable code without a stack trace contract', function (): void {
    $error = new Customs\Http\ApiError('VALIDATION_FAILED', 'Проверьте данные.', 422, ['field' => 'goods.grossMass']);
    same(422, $error->status);
    same('VALIDATION_FAILED', $error->errorCode);
    same('goods.grossMass', $error->details['field']);
});

test('production dependencies load on the chosen PHP runtime', function (): void {
    truth(class_exists(Brick\Math\BigDecimal::class));
    truth(class_exists(Mpdf\Mpdf::class));
    truth(class_exists(Opis\JsonSchema\Validator::class));
    same('0.30', (string) Brick\Math\BigDecimal::of('0.1')->plus('0.2')->toScale(2));
});
