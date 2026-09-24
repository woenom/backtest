<?php

use App\Models\Master;
use App\Models\Payment;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Хелпер для быстрой авторизации "на лету" без использования Middleware Closure
function getAuthenticatedMaster() {
    $masterId = request()->header('X-Master-Id');
    
    if (!$masterId) {
        abort(response()->json(['error' => 'Header X-Master-Id is missing'], 401));
    }
    
    $master = Master::find($masterId);
    if (!$master) {
        abort(response()->json(['error' => 'Master not found'], 401));
    }
    
    return $master;
}

// 1. Привязка реферала по коду
Route::post('/referrals/attach', function (Request $request) {
    $currentMaster = getAuthenticatedMaster();
    $code = $request->input('code');

    if (!$code) {
        return response()->json(['error' => 'Код обязателен'], 422);
    }

    // Ищем владельца кода
    $referrer = Master::where('referral_code', $code)->first();
    if (!$referrer) {
        return response()->json(['error' => 'Неверный реферальный код'], 404);
    }

    // За себя закрепиться нельзя
    if ($referrer->id === $currentMaster->id) {
        return response()->json(['error' => 'Нельзя использовать собственный код'], 422);
    }

    // Проверяем, нет ли уже созданной привязки для текущего мастера
    $exists = Referral::where('referred_master_id', $currentMaster->id)->exists();
    if ($exists) {
        return response()->json(['message' => 'Вы уже привязаны к рефереру'], 200);
    }

    // Создаем привязку
    Referral::create([
        'referrer_master_id' => $referrer->id,
        'referred_master_id' => $currentMaster->id,
        'status' => 'pending',
    ]);

    return response()->json(['message' => 'Реферал успешно привязан'], 201);
});

// 2. Список приведённых мной мастеров
Route::get('/referrals/my', function () {
    $currentMaster = getAuthenticatedMaster();

    // Получаем всех, кого привел текущий мастер
    $referrals = Referral::where('referrer_master_id', $currentMaster->id)->get();

    $result = $referrals->map(function ($referral) {
        $master = Master::find($referral->referred_master_id);
        
        // Считаем общую сумму успешных платежей
        $paymentsSum = Payment::where('master_id', $master->id)->sum('amount');
        
        // Условие "засчитан": если есть хотя бы один платеж больше 0
        $isConfirmed = Payment::where('master_id', $master->id)->where('amount', '>', 0)->exists();

        return [
            'name' => $master->name,
            'created_at' => $referral->created_at->toIso8601String(),
            'is_confirmed' => $isConfirmed,
            'earned' => $isConfirmed ? $paymentsSum : 0,
        ];
    });

    return response()->json($result);
});

// 3. Сводка по деньгам
Route::get('/referrals/earnings', function () {
    $currentMaster = getAuthenticatedMaster();

    // Находим id всех приглашенных мастеров
    $referredIds = Referral::where('referrer_master_id', $currentMaster->id)
        ->pluck('referred_master_id');

    $totalEarned = 0;
    $pendingEarned = 0;
    $confirmedCount = 0;

    foreach ($referredIds as $id) {
        $hasPaid = Payment::where('master_id', $id)->where('amount', '>', 0)->exists();
        $sum = Payment::where('master_id', $id)->sum('amount');

        if ($hasPaid) {
            $totalEarned += $sum;
            $confirmedCount++;
        } else {
            $pendingEarned += $sum; 
        }
    }

    return response()->json([
        'total_earned' => $totalEarned,
        'pending_earned' => $pendingEarned,
        'paid_out' => 0,
        'confirmed_referrals_count' => $confirmedCount,
    ]);
});
// 4. Пинг
Route::get('/ping', fn () => ['ok' => true]);