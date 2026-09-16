const { createApp, ref, reactive, onMounted } = Vue;

        createApp({
            setup() {
                const loading = ref(true);
                const paymentComplete = ref(false);
                const processingPayment = ref(false);
                const selectedTab = ref('gcash');
                const gcashStep = ref(1);
                const gcashNumber = ref('9171234567');
                const mayaNumber = ref('09181234567');
                const cardNumber = ref('4111 2222 3333 4444');
                const otpDigits = reactive(['', '', '', '', '', '']);
                const otpCode = ref('');
                const mpin = ref('1234');
                const countdown = ref(3);

                const session = reactive({
                    sessionId: '',
                    referenceNumber: '',
                    amount: 0,
                    description: 'GNCP Tuition & Matriculation Fee',
                    qrPhPayload: '',
                    returnUrl: ''
                });

                const settlementData = reactive({
                    transactionRef: '',
                    balance: 0,
                    status: 'PAID'
                });

                const formatCurrency = (val) => {
                    const num = parseFloat(val) || 0;
                    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };

                const parseUrlParams = () => {
                    const params = new URLSearchParams(window.location.search);
                    session.sessionId = params.get('session_id') || ('cs_test_' + Math.random().toString(36).substring(2, 10));
                    session.referenceNumber = params.get('ref') || 'GNCP-2026-133199';
                    session.amount = parseFloat(params.get('amount')) || 3000.00;
                    session.description = params.get('desc') || 'GNCP Tuition & Matriculation Fee';
                    session.returnUrl = params.get('return_url') || (document.referrer || '../../stations/payment-processing/index.html');
                    session.qrPhPayload = `00020101021226580014ph.paymongo.qr0111${session.sessionId}5408${session.amount}5802PH5910GNCP_COLLEGE62150111${session.referenceNumber}`;
                    
                    setTimeout(() => {
                        loading.value = false;
                    }, 400);
                };

                const proceedGcashOtp = () => {
                    gcashStep.value = 2;
                    otpCode.value = '123456';
                    setTimeout(() => {
                        for (let i = 0; i < 6; i++) {
                            const el = document.getElementById('otp-' + i);
                            if (el) el.value = '123456'[i];
                        }
                    }, 50);
                };

                const onOtpInput = (idx, e) => {
                    const val = e.target.value;
                    if (val && idx < 5) {
                        const next = document.getElementById('otp-' + (idx + 1));
                        if (next) next.focus();
                    }
                    let full = '';
                    for (let i = 0; i < 6; i++) {
                        const el = document.getElementById('otp-' + i);
                        full += (el ? el.value : '');
                    }
                    otpCode.value = full;
                };

                const proceedGcashMpin = () => {
                    gcashStep.value = 3;
                };

                const completePayment = async (channel) => {
                    processingPayment.value = true;
                    try {
                        const txnRef = 'PM-' + channel.toUpperCase() + '-' + Date.now().toString().slice(-6);
                        
                        const res = await fetch('../../api/index.php?action=payments/paymongo_simulate_paid', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                referenceNumber: session.referenceNumber,
                                amount: session.amount,
                                channel: channel,
                                transactionRef: txnRef,
                                notes: `Online settlement via PayMongo ${channel}`
                            })
                        });

                        const data = await res.json();
                        if (data.success) {
                            settlementData.transactionRef = data.data?.transactionRef || txnRef;
                            settlementData.balance = data.data?.balance != null ? data.data.balance : 0;
                            settlementData.status = data.data?.status || 'PAID';
                            paymentComplete.value = true;

                            // Auto redirect countdown
                            const timer = setInterval(() => {
                                countdown.value--;
                                if (countdown.value <= 0) {
                                    clearInterval(timer);
                                    returnToMerchant();
                                }
                            }, 1000);
                        } else {
                            alert(data.message || 'Payment settlement failed.');
                        }
                    } catch (e) {
                        alert('Payment authorization failed: ' + e.message);
                    } finally {
                        processingPayment.value = false;
                    }
                };

                const returnToMerchant = () => {
                    const sep = session.returnUrl.includes('?') ? '&' : '?';
                    const target = `${session.returnUrl}${sep}status=success&session_id=${session.sessionId}&ref=${session.referenceNumber}&amount=${session.amount}`;
                    window.location.href = target;
                };

                onMounted(() => {
                    parseUrlParams();
                });

                return {
                    loading,
                    session,
                    selectedTab,
                    gcashStep,
                    gcashNumber,
                    mayaNumber,
                    cardNumber,
                    otpDigits,
                    otpCode,
                    mpin,
                    processingPayment,
                    paymentComplete,
                    settlementData,
                    countdown,
                    formatCurrency,
                    proceedGcashOtp,
                    onOtpInput,
                    proceedGcashMpin,
                    completePayment,
                    returnToMerchant
                };
            }
        }).mount('#app');
