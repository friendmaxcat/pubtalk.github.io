// В файле index.php добавьте/измените следующие части:

// 1. В функции обработки завершения раунда аукциона (в action === 'get_auction'):
if ($action === 'get_auction') {
    $auction = safe_read($AUCTION_FILE);
    if (empty($auction)) {
        send_json(['ok'=>1, 'auction' => null]);
    }
    
    $auction = $auction[0];
    
    // Проверяем, не закончился ли раунд
    if (!empty($auction['round_end_at'])) {
        $round_end = strtotime($auction['round_end_at']);
        $now = time();
        
        if ($now > $round_end) {
            // Завершаем раунд
            if (!empty($auction['current_bids']) && count($auction['current_bids']) > 0) {
                // Находим победителя
                $max_bid = max(array_column($auction['current_bids'], 'amount'));
                $winner_bids = array_filter($auction['current_bids'], fn($bid) => $bid['amount'] == $max_bid);
                $winner_bid = reset($winner_bids);
                
                if ($winner_bid) {
                    // Добавляем победителя в историю
                    $auction['winners'][] = [
                        'user_id' => $winner_bid['user_id'],
                        'amount' => $winner_bid['amount'],
                        'round' => $auction['current_round'],
                        'won_at' => date('c')
                    ];
                    
                    // Переводим звезды от всех участников к победителю
                    $users = safe_read($USERS_FILE);
                    $total_stars = 0;
                    
                    foreach ($auction['current_bids'] as $bid) {
                        foreach ($users as &$u) {
                            if ($u['id'] == $bid['user_id']) {
                                $u['stars'] = ($u['stars'] ?? 0) - $bid['amount'];
                                $total_stars += $bid['amount'];
                            }
                        }
                    }
                    
                    // Начисляем победителю общую сумму ставок
                    foreach ($users as &$u) {
                        if ($u['id'] == $winner_bid['user_id']) {
                            $u['stars'] = ($u['stars'] ?? 0) + $total_stars;
                        }
                    }
                    
                    // Если это последний раунд, победитель получает подарок в инвентарь
                    if ($auction['current_round'] == $auction['total_rounds']) {
                        // Создаем запись в инвентаре
                        $inventory = safe_read($INVENTORY_FILE);
                        
                        $shop_item = [
                            'id' => 'auction_'.$auction['id'].'_round_'.$auction['current_round'],
                            'tab' => 'auction',
                            'name' => $auction['name'] . ' (Аукцион)',
                            'image' => $auction['gift_image'],
                            'type' => 'gift',
                            'price' => $max_bid,
                            'owner_id' => $winner_bid['user_id'],
                            'created_at' => date('c'),
                            'expires_at' => null // Бессрочный подарок
                        ];
                        
                        $inv_item = [
                            'id' => next_id($inventory),
                            'user_id' => $winner_bid['user_id'],
                            'shop_item_id' => $shop_item['id'],
                            'item_details' => $shop_item,
                            'created_at' => date('c'),
                            'auction_round' => $auction['current_round']
                        ];
                        
                        $inventory[] = $inv_item;
                        safe_write($INVENTORY_FILE, $inventory);
                        
                        // Добавляем в список победителей аукциона
                        $auction['final_winner'] = $winner_bid['user_id'];
                    }
                    
                    safe_write($USERS_FILE, $users);
                }
            }
            
            // Проверяем, нужно ли начинать новый раунд
            $auction['current_round']++;
            
            if ($auction['current_round'] <= $auction['total_rounds']) {
                // Начинаем новый раунд
                $auction['current_bids'] = [];
                $auction['round_end_at'] = date('c', time() + (5 * 60)); // 5 минут
                $auction['current_price'] = $auction['min_bid'];
            } else {
                // Аукцион завершен
                $auction['is_active'] = false;
            }
            
            safe_write($AUCTION_FILE, [$auction]);
        }
    }
    
    send_json(['ok'=>1, 'auction' => $auction]);
}

// 2. В функции place_bid сразу снимаем звезды при ставке:
if ($action === 'place_bid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $me = current_user(); if (!$me) send_json(['ok'=>0,'error'=>'auth']);
    
    $amount = (int)($_POST['amount'] ?? 0);
    
    if ($amount <= 0) send_json(['ok'=>0,'error'=>'Неверная сумма']);
    
    $auction = safe_read($AUCTION_FILE);
    if (empty($auction)) {
        send_json(['ok'=>0,'error'=>'Нет активного аукциона']);
    }
    
    $auction = $auction[0];
    
    if (!$auction['is_active']) {
        send_json(['ok'=>0,'error'=>'Аукцион не активен']);
    }
    
    if ($amount < $auction['current_price']) {
        send_json(['ok'=>0,'error'=>'Ставка должна быть не меньше текущей цены']);
    }
    
    // Проверяем баланс и снимаем звезды сразу
    $users = safe_read($USERS_FILE);
    $me_data_key = null;
    foreach ($users as $k=>$u) if ($u['id'] == $me['id']) { $me_data_key = $k; break; }
    if ($me_data_key === null) send_json(['ok'=>0,'error'=>'Пользователь не найден']);
    
    $me_stars = $users[$me_data_key]['stars'] ?? 0;
    
    if ($me_stars < $amount) {
        send_json(['ok'=>0,'error'=>'Недостаточно звёзд']);
    }
    
    // Снимаем звезды сразу
    $users[$me_data_key]['stars'] = $me_stars - $amount;
    safe_write($USERS_FILE, $users);
    
    // Удаляем предыдущую ставку этого пользователя (возвращаем старые звезды)
    $old_bid_amount = 0;
    foreach ($auction['current_bids'] as $key => $bid) {
        if ($bid['user_id'] == $me['id']) {
            $old_bid_amount = $bid['amount'];
            unset($auction['current_bids'][$key]);
            break;
        }
    }
    
    // Возвращаем старые звезды (если была предыдущая ставка)
    if ($old_bid_amount > 0) {
        foreach ($users as &$u) {
            if ($u['id'] == $me['id']) {
                $u['stars'] = ($u['stars'] ?? 0) + $old_bid_amount;
                break;
            }
        }
        safe_write($USERS_FILE, $users);
        unset($u);
    }
    
    // Добавляем новую ставку
    $auction['current_bids'][] = [
        'user_id' => $me['id'],
        'user_nick' => $me['nick'],
        'amount' => $amount,
        'placed_at' => date('c')
    ];
    
    // Обновляем текущую цену
    if ($amount > $auction['current_price']) {
        $auction['current_price'] = $amount;
    }
    
    safe_write($AUCTION_FILE, [$auction]);
    
    // Обновляем баланс пользователя в ответе
    $users = safe_read($USERS_FILE);
    $current_balance = 0;
    foreach ($users as $u) if ($u['id'] == $me['id']) { $current_balance = $u['stars']; break; }
    
    send_json(['ok'=>1, 'auction' => $auction, 'new_balance' => $current_balance]);
}
