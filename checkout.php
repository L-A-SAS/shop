<?php
// Prevent direct access to file
defined('shoppingcart') or exit;
// Default values for the input form elements
$account = [
    'first_name' => '',
    'last_name' => '',
    'address_street' => '',
    'address_city' => '',
    'address_state' => '',
    'address_zip' => '',
    'address_country' => 'United States'
];
// Error array, output errors on the form
$errors = [];
// Check if user is logged in
if (isset($_SESSION['account_loggedin'])) {
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([ $_SESSION['account_id'] ]);
    // Fetch the account from the database and return the result as an Array
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}
// Make sure when the user submits the form all data was submitted and shopping cart is not empty
if (isset($_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'], $_SESSION['cart'])) {
    $account_id = null;
    // If the user is already logged in
    if (isset($_SESSION['account_loggedin'])) {
        // Account logged-in, update the user's details
        $stmt = $pdo->prepare('UPDATE accounts SET first_name = ?, last_name = ?, address_street = ?, address_city = ?, address_state = ?, address_zip = ?, address_country = ? WHERE id = ?');
        $stmt->execute([ $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'], $_SESSION['account_id'] ]);
        $account_id = $_SESSION['account_id'];
    } else if (isset($_POST['email'], $_POST['password'], $_POST['cpassword']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        // User is not logged in, check if the account already exists with the email they submitted
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
        $stmt->execute([ $_POST['email'] ]);
    	if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            // Email exists, user should login instead...
    		$errors[] = 'קיים חשבון למייל זה';
        }
        if (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5) {
            // Password must be between 5 and 20 characters long.
            $errors[] = 'סיסמה בין 5-20 תווים';
    	}
        if ($_POST['password'] != $_POST['cpassword']) {
            // Password and confirm password fields do not match...
            $errors[] = 'סיסמה לא תואמת';
        }
        if (!$errors) {
            // Email doesnt exist, create new account
            $stmt = $pdo->prepare('INSERT INTO accounts (email, password, first_name, last_name, address_street, address_city, address_state, address_zip, address_country) VALUES (?,?,?,?,?,?,?,?,?)');
            // Hash the password
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt->execute([ $_POST['email'], $password, $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'] ]);
            $account_id = $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
            $stmt->execute([ $account_id ]);
            // Fetch the account from the database and return the result as an Array
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else if (account_required) {
        $errors[] = 'Account creation required!';
    }
    if (!$errors) {
        // No errors, process the order
        $products_in_cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
        $subtotal = 0.00;
        $shippingtotal = 0.00;
        $discounttotal = 0.00;
        $selected_shipping_method = isset($_SESSION['shipping_method']) ? $_SESSION['shipping_method'] : null;
        // If there are products in cart
        if ($products_in_cart) {
            // There are products in the cart so we need to select those products from the database
            // Products in cart array to question mark string array, we need the SQL statement to include: IN (?,?,?,...etc)
            $array_to_question_marks = implode(',', array_fill(0, count($products_in_cart), '?'));
            $stmt = $pdo->prepare('SELECT p.id, c.id AS category_id, p.* FROM products p LEFT JOIN products_categories pc ON p.id = pc.product_id LEFT JOIN categories c ON c.id = pc.category_id WHERE p.id IN (' . $array_to_question_marks . ') GROUP BY p.id, c.id');
            // We use the array_column to retrieve only the id's of the products
            $stmt->execute(array_column($products_in_cart, 'id'));
            // Fetch the products from the database and return the result as an Array
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Retrieve the discount code
            if (isset($_SESSION['discount'])) {
                $stmt = $pdo->prepare('SELECT * FROM discounts WHERE discount_code = ?');
                $stmt->execute([ $_SESSION['discount'] ]);
                $discount = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            // Get the current date
            $current_date = strtotime((new DateTime())->format('Y-m-d H:i:s'));
            // Retrieve shipping methods
            $stmt = $pdo->query('SELECT * FROM shipping');
            $shipping_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $selected_shipping_method = $selected_shipping_method == null && $shipping_methods ? $shipping_methods[0]['name'] : $selected_shipping_method;
            // Iterate the products in cart and add the meta data (product name, desc, etc)
            foreach ($products_in_cart as &$cart_product) {
                foreach ($products as $product) {
                    if ($cart_product['id'] == $product['id']) {
                        $cart_product['meta'] = $product;
                        // Calculate the subtotal
                        $product_price = $cart_product['options_price'] > 0 ? (float)$cart_product['options_price'] : (float)$product['price'];
                        $subtotal += $product_price * (int)$cart_product['quantity'];
                        // Calculate the shipping
                        foreach ($shipping_methods as $shipping_method) {
                            if ($shipping_method['name'] == $selected_shipping_method && $product_price >= $shipping_method['price_from'] && $product_price <= $shipping_method['price_to'] && $product['weight'] >= $shipping_method['weight_from'] && $product['weight'] <= $shipping_method['weight_to']) {
                                $cart_product['shipping_price'] = (float)$shipping_method['price'] * (int)$cart_product['quantity'];
                                $shippingtotal += $cart_product['shipping_price'];
                            }
                        }
                        // Check which products are eligible for a discount
                        if (isset($discount) && $discount && $current_date >= strtotime($discount['start_date']) && $current_date <= strtotime($discount['end_date'])) {
                            if ((empty($discount['category_ids']) && empty($discount['product_ids'])) || in_array($product['id'], explode(',', $discount['product_ids'])) || (!empty($product['category_id']) && in_array($product['category_id'], explode(',', $discount['category_ids'])))) {
                                $cart_product['discounted'] = true;
                            }
                        }
                    }
                }
            }
            // Number of discounted products
            $num_discounted_products = count(array_column($products_in_cart, 'discounted'));
            // Iterate the products and update the price for the discounted products
            foreach ($products_in_cart as &$cart_product) {
                if (isset($cart_product['discounted']) && $cart_product['discounted']) {
                    if ($cart_product['options_price'] > 0) {
                        $price = &$cart_product['options_price'];
                    } else {
                        $price = &$cart_product['meta']['price'];
                    }
                    if ($discount['discount_type'] == 'Percentage') {
                        $d = round((float)$price * ((float)$discount['discount_value']/100), 2);
                        $price -= $d;
                        $discounttotal += $d * (int)$cart_product['quantity'];
                    }
                    if ($discount['discount_type'] == 'Fixed') {
                        $d = round((float)$discount['discount_value'] / $num_discounted_products, 2);
                        $price -= round($d / (int)$cart_product['quantity'], 2);
                        $discounttotal += $d;
                    }
                }
            }
        }
        // Process Stripe Payment
        if (isset($_POST['stripe']) && $products_in_cart) {
            // Include the stripe lib
            require_once('lib/stripe/init.php');
            $stripe = new \Stripe\StripeClient(stripe_secret_key);
            $line_items = [];
            // Iterate the products in cart and add each product to the array above
            for ($i = 0; $i < count($products_in_cart); $i++) {
                $line_items[] = [
                    'quantity' => $products_in_cart[$i]['quantity'],
                    'price_data' => [
                        'currency' => stripe_currency,
                        'unit_amount' => ($products_in_cart[$i]['options_price'] > 0 ? $products_in_cart[$i]['options_price'] : $products_in_cart[$i]['meta']['price'])*100,
                        'product_data' => [
                            'name' => $products_in_cart[$i]['meta']['name'],
                            'metadata' => [
                                'item_id' => $products_in_cart[$i]['id'],
                                'item_options' => $products_in_cart[$i]['options'],
                                'item_shipping' => $products_in_cart[$i]['shipping_price']
                            ]
                        ]
                    ]
                ];
            }
            // Add the shipping
            $line_items[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => stripe_currency,
                    'unit_amount' => $shippingtotal*100,
                    'product_data' => [
                        'name' => 'Shipping',
                        'description' => $selected_shipping_method,
                        'metadata' => [
                            'item_id' => 'shipping'
                        ]
                    ]
                ]
            ];
            // Webhook that will notify the stripe IPN file when a payment has been made
            $webhooks = $stripe->webhookEndpoints->all();
            $webhook = null;
            $secret = '';
            foreach ($webhooks as $wh) {
                if ($wh['description'] == 'codeshack_shoppingcart_system') {
                    $webhook = $wh;
                    $secret = $webhook['metadata']['secret'];
                }
            }
            if ($webhook == null) {
                $webhook = $stripe->webhookEndpoints->create([
                    'url' => stripe_ipn_url,
                    'description' => 'codeshack_shoppingcart_system',
                    'enabled_events' => ['checkout.session.completed'],
                    'metadata' => ['secret' => '']
                ]);
                $secret = $webhook['secret'];
                $stripe->webhookEndpoints->update($webhook['id'], ['metadata' => ['secret' => $secret] ]);
            }
            $stripe->webhookEndpoints->update($webhook['id'], ['url' => stripe_ipn_url . '?key=' . $secret]);
            // Create the stripe checkout session and redirect the user
            $session = $stripe->checkout->sessions->create([
                'success_url' => stripe_return_url,
                'cancel_url' => stripe_cancel_url,
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => 'payment',
                'customer_email' => isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'],
                'metadata' => [
                    'first_name' => $_POST['first_name'],
                    'last_name' => $_POST['last_name'],
                    'address_street' => $_POST['address_street'],
                    'address_city' => $_POST['address_city'],
                    'address_state' => $_POST['address_state'],
                    'address_zip' => $_POST['address_zip'],
                    'address_country' => $_POST['address_country'],
                    'account_id' => $account_id
                ]
            ]);
            header('Location: stripe-redirect.php?stripe_session_id=' . $session['id']);
            exit;
        }
        // Process PayPal Payment
        if (isset($_POST['paypal']) && $products_in_cart) {
            // Process PayPal Checkout
            // Variable that will stored all details for all products in the shopping cart
            $data = [];
            // Add all the products that are in the shopping cart to the data array variable
            for ($i = 0; $i < count($products_in_cart); $i++) {
                $data['item_number_' . ($i+1)] = $products_in_cart[$i]['id'];
                $data['item_name_' . ($i+1)] = str_replace(['(', ')'], '', $products_in_cart[$i]['meta']['name']);
                $data['quantity_' . ($i+1)] = $products_in_cart[$i]['quantity'];
                $data['amount_' . ($i+1)] = $products_in_cart[$i]['options_price'] > 0 ? $products_in_cart[$i]['options_price'] : $products_in_cart[$i]['meta']['price'];
                $data['on0_' . ($i+1)] = 'Options';
                $data['os0_' . ($i+1)] = $products_in_cart[$i]['options'];
                $data['shipping_' . ($i+1)] = $products_in_cart[$i]['shipping_price'];
            }
            // Variables we need to pass to paypal
            $data = $data + [
                'cmd'			=> '_cart',
                'upload'        => '1',
                'custom'        => $account_id,
                'business' 		=> paypal_email,
                'cancel_return'	=> paypal_cancel_url,
                'notify_url'	=> paypal_ipn_url,
                'currency_code'	=> paypal_currency,
                'return'        => paypal_return_url
            ];
            if ($account_id != null) {
                // Log the user in with the details provided
                session_regenerate_id();
                $_SESSION['account_loggedin'] = TRUE;
                $_SESSION['account_id'] = $account_id;
                $_SESSION['account_admin'] = $account ? $account['admin'] : 0;
            }
            // Redirect the user to the PayPal checkout screen
            header('location:' . (paypal_testmode ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr') . '?' . http_build_query($data));
            // End the script, don't need to execute anything else
            exit;
        }
        if (isset($_POST['checkout']) && $products_in_cart) {
            // Process Normal Checkout
            // Iterate each product in the user's shopping cart
            // Unique transaction ID
            $transaction_id = strtoupper(uniqid('SC') . substr(md5(mt_rand()), 0, 5));
            $stmt = $pdo->prepare('INSERT INTO transactions (txn_id, payment_amount, payment_status, created, payer_email, first_name, last_name, address_street, address_city, address_state, address_zip, address_country, account_id, payment_method) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $transaction_id,
                ($subtotal-$discounttotal)+$shippingtotal,
                'Completed',
                date('Y-m-d H:i:s'),
                isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'],
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['address_street'],
                $_POST['address_city'],
                $_POST['address_state'],
                $_POST['address_zip'],
                $_POST['address_country'],
                $account_id,
                'website'
            ]);
            $order_id = $pdo->lastInsertId();
            foreach ($products_in_cart as $product) {
                // For every product in the shopping cart insert a new transaction into our database
                $stmt = $pdo->prepare('INSERT INTO transactions_items (txn_id, item_id, item_price, item_quantity, item_options, item_shipping_price) VALUES (?,?,?,?,?,?)');
                $stmt->execute([ $transaction_id, $product['id'], $product['options_price'] > 0 ? $product['options_price'] : $product['meta']['price'], $product['quantity'], $product['options'], $product['shipping_price'] ]);
                // Update product quantity in the products table
                $stmt = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE quantity > 0 AND id = ?');
                $stmt->execute([ $product['quantity'], $product['id'] ]);
            }
            if ($account_id != null) {
                // Log the user in with the details provided
                session_regenerate_id();
                $_SESSION['account_loggedin'] = TRUE;
                $_SESSION['account_id'] = $account_id;
                $_SESSION['account_admin'] = $account ? $account['admin'] : 0;
            }
            send_order_details_email(
                isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'],
                $products_in_cart,
                $_POST['first_name'],
                $_POST['last_name'],
                $_POST['address_street'],
                $_POST['address_city'],
                $_POST['address_state'],
                $_POST['address_zip'],
                $_POST['address_country'],
                ($subtotal-$discounttotal)+$shippingtotal,
                $order_id
            );
            header('Location: ' . url('index.php?page=placeorder'));
            exit;
        }
    }
    // Preserve form details if the user encounters an error
    $account = [
        'first_name' => $_POST['first_name'],
        'last_name' => $_POST['last_name'],
        'address_street' => $_POST['address_street'],
        'address_city' => $_POST['address_city'],
        'address_state' => $_POST['address_state'],
        'address_zip' => $_POST['address_zip'],
        'address_country' => $_POST['address_country']
    ];
}
// Redirect the user if the shopping cart is empty
if (empty($_SESSION['cart'])) {
    header('Location: ' . url('index.php?page=cart'));
    exit;
}
// List of countries available, feel free to remove any country from the array
$countries = ["Israel"];

?>
<?=template_header('Checkout')?>

<div class="checkout content-wrapper">

    <h1>סיים הזמנה</h1>

    <p style="text-align:right;" class="error"><?=implode('<br>', $errors)?></p>

    <?php if (!isset($_SESSION['account_loggedin'])): ?>
    <p style="text-align:right;">כבר יש לך חשבון?  <a href="<?=url('index.php?page=myaccount')?>">התחבר</a></p>
    <?php endif; ?>

    <form action="" method="post">

        <?php if (!isset($_SESSION['account_loggedin'])): ?>
        <h2 style="text-align:right;">צור חשבון<?php if (!account_required): ?> (optional)<?php endif; ?></h2>

        <label for="email">&nbsp</label>
        <input style="text-align:right;" type="email" name="email" id="email" placeholder="john@example.com">
        
        <label for="password">&nbsp</label>
        <input style="text-align:right;" type="password" name="password" id="password" placeholder="סיסמה">
        <label for="cpassword">&nbsp</label>
        <input style="text-align:right;" type="password" name="cpassword" id="cpassword" placeholder="אמת סיסמה">
        <?php endif; ?>

        <h2 style="text-align:right;">כתובת למשלוח הזמנה</h2>

        <div class="row1">
            <label for="first_name">&nbsp</label>
            <input style="text-align:right;" type="text" value="<?=$account['first_name']?>" name="first_name" id="first_name" placeholder="שם פרטי" required>
        </div>

        <div class="row2">
            <label for="last_name">&nbsp</label>
            <input style="text-align:right;" type="text" value="<?=$account['last_name']?>" name="last_name" id="last_name" placeholder="שם פרטי" required>
        </div>

        <label for="address_street">&nbsp</label>
        <input style="text-align:right;" type="text" value="<?=$account['address_street']?>" name="address_street" id="address_street" placeholder="כתובת" required>

        <label for="address_city">&nbsp</label>
        <input style="text-align:right;"type="text" value="<?=$account['address_city']?>" name="address_city" id="address_city" placeholder="עיר" required>
        <!--
        <div class="row1">
            <label for="address_state">&nbsp</label>
            <input style="text-align:right;"type="text" value="<?=$account['address_state']?>" name="address_state" id="address_state" placeholder="מדינה" required>
        </div>
        -->
        
        <div class="row1">
            <label for="address_zip">&nbsp</label>
            <input style="text-align:right;" type="text" value="<?=$account['address_zip']?>" name="address_zip" id="address_zip" placeholder="מיקוד" required>
        </div>
        <div class="row2">
        <label for="address_country">&nbsp</label>
        <select name="address_country" required>
            <?php foreach($countries as $country): ?>
            <option value="<?=$country?>"<?=$country==$account['address_country']?' selected':''?>><?=$country?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <button type="submit" name="checkout">סיים הזמנה</button>
        <!--
        <//?php if (stripe_enabled): ?>
        <div class="stripe">
            <button type="submit" name="stripe">Pay with Stripe Checkout</button>
        </div>
        <//?php endif; ?>
        -->
        <?php if (paypal_enabled): ?>
        <div class="paypal">
            <button type="submit" name="paypal"><img src="https://www.paypalobjects.com/webstatic/mktg/Logo/pp-logo-100px.png" alt="PayPal Logo"></button>
        </div>
        <?php endif; ?>

    </form>

</div>

<?=template_footer()?>
