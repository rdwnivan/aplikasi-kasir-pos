import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    History,
    LayoutGrid,
    LogOut,
    PauseCircle,
    Percent,
    ScanBarcode,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CartPanel } from '@/components/pos/cart-panel';
import { DiscountDialog } from '@/components/pos/discount-dialog';
import { HeldSalesDialog } from '@/components/pos/held-sales-dialog';
import { HoldDialog } from '@/components/pos/hold-dialog';
import { Kbd } from '@/components/pos/kbd';
import { PaymentDialog } from '@/components/pos/payment-dialog';
import { QrisDialog } from '@/components/pos/qris-dialog';
import { ProductResults } from '@/components/pos/product-results';
import { SaleCompleteDialog } from '@/components/pos/sale-complete-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/money';
import { priceCart } from '@/lib/pricing';
import { dashboard } from '@/routes';
import pos from '@/routes/pos';
import type {
    CartItem,
    CatalogItem,
    DiscountType,
    HeldSale,
    LastSale,
    PendingQris,
    ShiftSummary,
    TaxConfig,
} from '@/types/pos';

type Props = {
    shift: ShiftSummary;
    catalog: CatalogItem[];
    heldSales: HeldSale[];
    tax: TaxConfig;
    lastSale: LastSale | null;
    qrisEnabled: boolean;
    pendingQris: PendingQris | null;
};

type Discount = { type: DiscountType | null; value: number };

type StoredCart = {
    items: CartItem[];
    discount: Discount;
    heldId: number | null;
    heldLabel: string;
};

const MAX_RESULTS = 40;
const timeFormat = new Intl.DateTimeFormat('id-ID', { timeStyle: 'short' });

export default function PosIndex({
    shift,
    catalog,
    heldSales,
    tax,
    lastSale,
    qrisEnabled,
    pendingQris,
}: Props) {
    const { auth } = usePage().props;
    const storageKey = `pos-cart-${shift.id}`;

    const byId = useMemo(
        () => new Map(catalog.map((item) => [item.id, item])),
        [catalog],
    );
    const byBarcode = useMemo(
        () =>
            new Map(
                catalog
                    .filter((item) => item.barcode)
                    .map((item) => [item.barcode as string, item]),
            ),
        [catalog],
    );

    // Cart survives a page refresh (per shift, per browser). Server state is the source of
    // truth for everything else, so this is only a convenience.
    const [stored] = useState<StoredCart | null>(() =>
        readStoredCart(storageKey),
    );
    const [cart, setCart] = useState<CartItem[]>(() =>
        (stored?.items ?? []).filter((i) => byId.has(i.productUnitId)),
    );
    const [cartDiscount, setCartDiscount] = useState<Discount>(
        stored?.discount ?? { type: null, value: 0 },
    );
    const [heldId, setHeldId] = useState<number | null>(stored?.heldId ?? null);
    const [heldLabel, setHeldLabel] = useState(stored?.heldLabel ?? '');

    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(0);
    const [dialog, setDialog] = useState<
        'pay' | 'hold' | 'held' | 'discount' | null
    >(null);
    const [discountTarget, setDiscountTarget] = useState<number | 'cart'>(
        'cart',
    );
    const [processing, setProcessing] = useState(false);
    const [payError, setPayError] = useState<string>();
    const [completed, setCompleted] = useState<LastSale | null>(lastSale);

    const searchRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        writeStoredCart(storageKey, {
            items: cart,
            discount: cartDiscount,
            heldId,
            heldLabel,
        });
    }, [storageKey, cart, cartDiscount, heldId, heldLabel]);

    useEffect(() => setCompleted(lastSale), [lastSale]);

    const results = useMemo(
        () => searchCatalog(catalog, query).slice(0, MAX_RESULTS),
        [catalog, query],
    );

    const priced = useMemo(
        () =>
            priceCart(
                cart.map((line) => ({
                    unitPrice: byId.get(line.productUnitId)?.price ?? 0,
                    qty: line.qty,
                    discountType: line.discountType,
                    discountValue: line.discountValue,
                })),
                tax,
                cartDiscount.type,
                cartDiscount.value,
            ),
        [cart, byId, tax, cartDiscount],
    );

    // Products whose cart quantity (across all their units) exceeds stock on hand.
    const shortProducts = useMemo(() => {
        const needed = new Map<number, number>();
        for (const line of cart) {
            const item = byId.get(line.productUnitId);
            if (item) {
                needed.set(
                    item.productId,
                    (needed.get(item.productId) ?? 0) +
                        line.qty * item.conversion,
                );
            }
        }

        return new Set(
            [...needed]
                .filter(
                    ([productId, qty]) =>
                        qty >
                        (catalog.find((c) => c.productId === productId)
                            ?.stock ?? 0),
                )
                .map(([id]) => id),
        );
    }, [cart, byId, catalog]);

    const itemCount = cart.reduce((sum, line) => sum + line.qty, 0);
    const busy = dialog !== null || completed !== null;

    function focusSearch() {
        // Deferred so it runs after the current render. By then the cashier may already have
        // opened another dialog (fast F-key sequences), and stealing focus from it would
        // swallow their Enter, so back off if any dialog is open.
        requestAnimationFrame(() => {
            if (!document.querySelector('[role="dialog"]')) {
                searchRef.current?.focus();
            }
        });
    }

    function addToCart(item: CatalogItem, qty = 1) {
        setCart((current) => {
            const index = current.findIndex(
                (line) =>
                    line.productUnitId === item.id &&
                    line.discountType === null,
            );
            if (index === -1) {
                return [
                    ...current,
                    {
                        productUnitId: item.id,
                        qty,
                        discountType: null,
                        discountValue: 0,
                    },
                ];
            }

            return current.map((line, i) =>
                i === index ? { ...line, qty: line.qty + qty } : line,
            );
        });
        setQuery('');
        setHighlighted(0);
        focusSearch();
    }

    function setQty(index: number, qty: number) {
        setCart((current) =>
            current.map((line, i) =>
                i === index
                    ? { ...line, qty: Math.max(1, Math.min(qty, 99999)) }
                    : line,
            ),
        );
    }

    function removeLine(index: number) {
        setCart((current) => current.filter((_, i) => i !== index));
        focusSearch();
    }

    function resetCart() {
        setCart([]);
        setCartDiscount({ type: null, value: 0 });
        setHeldId(null);
        setHeldLabel('');
        focusSearch();
    }

    function payload() {
        return {
            items: cart.map((line) => ({
                product_unit_id: line.productUnitId,
                qty: line.qty,
                discount_type: line.discountType,
                discount_value: line.discountValue,
            })),
            discount_type: cartDiscount.type,
            discount_value: cartDiscount.value,
            held_sale_id: heldId,
        };
    }

    function submitSale(paid: number) {
        setPayError(undefined);
        router.post(
            pos.sales.store.url(),
            { ...payload(), paid_amount: paid },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setDialog(null);
                    resetCart();
                },
                onError: (errors) => setPayError(firstError(errors)),
            },
        );
    }

    function startQris() {
        setPayError(undefined);
        router.post(pos.qris.store.url(), payload(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setDialog(null);
                // A held cart was turned into the QRIS sale; it no longer exists as held.
                setHeldId(null);
                setHeldLabel('');
            },
            onError: (errors) => setPayError(firstError(errors)),
        });
    }

    const onQrisPaid = useCallback(() => {
        resetCart();
        router.reload();
    }, []);

    function holdCart(label: string) {
        router.post(
            pos.held.store.url(),
            { ...payload(), label: label || null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setDialog(null);
                    resetCart();
                },
                onError: (errors) => toast.error(firstError(errors)),
            },
        );
    }

    function resumeHeld(sale: HeldSale) {
        const items = sale.items.filter((item) => byId.has(item.productUnitId));
        if (items.length < sale.items.length) {
            toast.warning(
                'Sebagian produk sudah tidak aktif dan tidak dimasukkan ke keranjang.',
            );
        }
        setCart(items);
        setCartDiscount({ type: sale.discountType, value: sale.discountValue });
        setHeldId(sale.id);
        setHeldLabel(sale.label ?? '');
        setDialog(null);
        focusSearch();
    }

    function discardHeld(sale: HeldSale) {
        if (
            !window.confirm(
                `Hapus transaksi "${sale.label || 'Tanpa nama'}"? Tindakan ini tidak bisa dibatalkan.`,
            )
        ) {
            return;
        }
        router.delete(pos.held.destroy.url(sale.id), {
            preserveScroll: true,
            onSuccess: () => heldId === sale.id && resetCart(),
            onError: (errors) => toast.error(firstError(errors)),
        });
    }

    function openDiscount(target: number | 'cart') {
        setDiscountTarget(target);
        setDialog('discount');
    }

    function applyDiscount(type: DiscountType | null, value: number) {
        if (discountTarget === 'cart') {
            setCartDiscount({ type, value: type ? value : 0 });
        } else {
            setCart((current) =>
                current.map((line, i) =>
                    i === discountTarget
                        ? {
                              ...line,
                              discountType: type,
                              discountValue: type ? value : 0,
                          }
                        : line,
                ),
            );
        }
        setDialog(null);
        focusSearch();
    }

    function printReceipt(sale: LastSale) {
        window.open(
            pos.receipts.show.url(sale.id, { query: { print: 1 } }),
            '_blank',
            'noopener,width=420,height=640',
        );
        setCompleted(null);
        focusSearch();
    }

    function onSearchKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHighlighted((h) => Math.min(h + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHighlighted((h) => Math.max(h - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const term = query.trim();
            // A USB barcode scanner types the code and presses Enter.
            const scanned = byBarcode.get(term);
            if (scanned) {
                addToCart(scanned);
            } else if (term && results[highlighted]) {
                addToCart(results[highlighted]);
            } else if (term) {
                toast.error(`Produk “${term}” tidak ditemukan.`);
                setQuery('');
            }
        } else if (e.key === 'Escape') {
            setQuery('');
            setHighlighted(0);
        }
    }

    // Global shortcuts. Function keys are reserved for the till, so the browser defaults
    // (F5 reload, etc.) are suppressed only for the keys we use.
    useEffect(() => {
        function onKeyDown(e: KeyboardEvent) {
            if (completed !== null) {
                return; // the success dialog handles its own keys
            }
            switch (e.key) {
                case 'F2':
                    e.preventDefault();
                    setDialog(null);
                    focusSearch();
                    break;
                case 'F4':
                    e.preventDefault();
                    if (cart.length > 0 && dialog === null) setDialog('hold');
                    break;
                case 'F7':
                    e.preventDefault();
                    if (cart.length > 0 && dialog === null)
                        openDiscount('cart');
                    break;
                case 'F8':
                    e.preventDefault();
                    if (dialog === null) setDialog('held');
                    break;
                case 'F9':
                    e.preventDefault();
                    if (
                        cart.length > 0 &&
                        dialog === null &&
                        shortProducts.size === 0
                    ) {
                        setPayError(undefined);
                        setDialog('pay');
                    }
                    break;
            }
        }
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [cart.length, dialog, completed, shortProducts.size]);

    useEffect(() => {
        if (!busy) {
            focusSearch();
        }
    }, [busy]);

    const discountBase =
        discountTarget === 'cart'
            ? priced.subtotal
            : (priced.lines[discountTarget]?.gross ?? 0);
    const discountCurrent =
        discountTarget === 'cart'
            ? cartDiscount
            : {
                  type: cart[discountTarget]?.discountType ?? null,
                  value: cart[discountTarget]?.discountValue ?? 0,
              };

    return (
        <>
            <Head title="Kasir" />
            <div className="flex h-dvh flex-col bg-muted/40">
                <header className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b bg-background px-4 py-2.5">
                    <div className="mr-auto min-w-0">
                        <div className="truncate font-semibold">
                            Kasir · {auth.user.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Shift sejak{' '}
                            {timeFormat.format(new Date(shift.openedAt))} ·{' '}
                            {shift.salesCount} transaksi · kas{' '}
                            {formatRupiah(shift.expectedCash)}
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setDialog('held')}
                        aria-label="Transaksi ditahan"
                    >
                        <PauseCircle />
                        <span className="hidden sm:inline">Ditahan</span>
                        {heldSales.length > 0 && (
                            <Badge className="ml-1 px-1.5">
                                {heldSales.length}
                            </Badge>
                        )}
                        <Kbd className="ml-1 hidden md:inline-flex">F8</Kbd>
                    </Button>
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={pos.sales.index()}
                            aria-label="Riwayat transaksi"
                        >
                            <History />
                            <span className="hidden sm:inline">Riwayat</span>
                        </Link>
                    </Button>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={pos.shift.edit()} aria-label="Tutup shift">
                            <LogOut />
                            <span className="hidden sm:inline">
                                Tutup shift
                            </span>
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        asChild
                        aria-label="Dashboard"
                    >
                        <Link href={dashboard()}>
                            <LayoutGrid />
                        </Link>
                    </Button>
                </header>

                <main className="grid min-h-0 flex-1 grid-rows-[minmax(10rem,2fr)_minmax(17rem,3fr)] gap-3 p-3 lg:grid-cols-[minmax(0,1fr)_420px] lg:grid-rows-1">
                    <section className="flex min-h-0 flex-col overflow-hidden rounded-xl border bg-background">
                        <div className="border-b p-3">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    ref={searchRef}
                                    autoFocus
                                    aria-label="Cari produk atau scan barcode"
                                    placeholder="Scan barcode atau ketik nama / SKU…"
                                    className="h-11 pr-16 pl-9 text-base"
                                    value={query}
                                    onChange={(e) => {
                                        setQuery(e.target.value);
                                        setHighlighted(0);
                                    }}
                                    onKeyDown={onSearchKeyDown}
                                />
                                <div className="absolute top-1/2 right-3 flex -translate-y-1/2 items-center gap-1 text-muted-foreground">
                                    <ScanBarcode className="size-4" />
                                    <Kbd>F2</Kbd>
                                </div>
                            </div>
                        </div>
                        <ProductResults
                            items={results}
                            highlighted={highlighted}
                            query={query}
                            onPick={(item) => addToCart(item)}
                            onHover={setHighlighted}
                        />
                    </section>

                    <aside className="flex min-h-0 flex-col overflow-hidden rounded-xl border bg-background">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <div>
                                <div className="font-semibold">Keranjang</div>
                                <div className="text-xs text-muted-foreground">
                                    {heldId
                                        ? `Lanjutan: ${heldLabel || 'transaksi ditahan'} · `
                                        : ''}
                                    {itemCount} item
                                </div>
                            </div>
                            {cart.length > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive hover:text-destructive"
                                    onClick={() =>
                                        window.confirm(
                                            'Kosongkan keranjang?',
                                        ) && resetCart()
                                    }
                                >
                                    <Trash2 /> Kosongkan
                                </Button>
                            )}
                        </div>

                        <CartPanel
                            cart={cart}
                            catalog={byId}
                            priced={priced}
                            shortProducts={shortProducts}
                            onQty={setQty}
                            onRemove={removeLine}
                            onDiscount={openDiscount}
                        />

                        <div className="space-y-3 border-t p-4">
                            <dl className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Subtotal
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatRupiah(priced.subtotal)}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between">
                                    <dt>
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none"
                                            disabled={cart.length === 0}
                                            onClick={() => openDiscount('cart')}
                                        >
                                            <Percent className="size-3.5" />
                                            Diskon transaksi
                                            {cartDiscount.type === 'percent' &&
                                                cartDiscount.value > 0 &&
                                                ` ${cartDiscount.value}%`}
                                            <Kbd className="hidden md:inline-flex">
                                                F7
                                            </Kbd>
                                        </button>
                                    </dt>
                                    <dd className="tabular-nums">
                                        {priced.discount > 0
                                            ? `-${formatRupiah(priced.discount)}`
                                            : '-'}
                                    </dd>
                                </div>
                                {tax.enabled && (
                                    <div className="flex justify-between text-xs text-muted-foreground">
                                        <dt>
                                            PPN {tax.rate}%
                                            {tax.pricesIncludeTax
                                                ? ' (termasuk)'
                                                : ''}
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(priced.taxAmount)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <div className="flex items-baseline justify-between">
                                <span className="font-medium">Total</span>
                                <span className="text-3xl font-bold tracking-tight tabular-nums">
                                    {formatRupiah(priced.total)}
                                </span>
                            </div>
                            <div className="grid grid-cols-[auto_1fr] gap-2">
                                <Button
                                    variant="outline"
                                    size="lg"
                                    disabled={cart.length === 0}
                                    onClick={() => setDialog('hold')}
                                >
                                    Tahan <Kbd>F4</Kbd>
                                </Button>
                                <Button
                                    size="lg"
                                    className="text-base"
                                    disabled={
                                        cart.length === 0 ||
                                        shortProducts.size > 0
                                    }
                                    onClick={() => {
                                        setPayError(undefined);
                                        setDialog('pay');
                                    }}
                                >
                                    Bayar
                                    <Kbd className="bg-primary-foreground/20 text-primary-foreground">
                                        F9
                                    </Kbd>
                                </Button>
                            </div>
                            {shortProducts.size > 0 && (
                                <p className="text-xs text-destructive">
                                    Ada produk yang stoknya tidak cukup.
                                </p>
                            )}
                        </div>
                    </aside>
                </main>
            </div>

            <PaymentDialog
                open={dialog === 'pay'}
                total={priced.total}
                processing={processing}
                error={payError}
                qrisEnabled={qrisEnabled}
                onOpenChange={(open) => !open && setDialog(null)}
                onPay={submitSale}
                onQris={startQris}
            />
            <QrisDialog payment={pendingQris} onPaid={onQrisPaid} />
            <HoldDialog
                open={dialog === 'hold'}
                processing={processing}
                defaultLabel={heldLabel}
                onOpenChange={(open) => !open && setDialog(null)}
                onHold={holdCart}
            />
            <HeldSalesDialog
                open={dialog === 'held'}
                heldSales={heldSales}
                cartIsEmpty={cart.length === 0}
                onOpenChange={(open) => !open && setDialog(null)}
                onResume={resumeHeld}
                onDiscard={discardHeld}
            />
            <DiscountDialog
                open={dialog === 'discount'}
                title={
                    discountTarget === 'cart'
                        ? 'Diskon transaksi'
                        : `Diskon ${byId.get(cart[discountTarget]?.productUnitId ?? 0)?.name ?? 'item'}`
                }
                base={discountBase}
                type={discountCurrent.type}
                value={discountCurrent.value}
                onOpenChange={(open) => !open && setDialog(null)}
                onApply={applyDiscount}
            />
            <SaleCompleteDialog
                sale={completed}
                onPrint={printReceipt}
                onClose={() => setCompleted(null)}
            />
        </>
    );
}

function searchCatalog(catalog: CatalogItem[], query: string): CatalogItem[] {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    if (terms.length === 0) {
        return catalog;
    }

    return catalog.filter((item) => {
        const haystack =
            `${item.name} ${item.sku} ${item.barcode ?? ''} ${item.category ?? ''} ${item.unit}`.toLowerCase();

        return terms.every((term) => haystack.includes(term));
    });
}

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Terjadi kesalahan. Coba lagi.';
}

function readStoredCart(key: string): StoredCart | null {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as StoredCart) : null;
    } catch {
        return null;
    }
}

function writeStoredCart(key: string, cart: StoredCart): void {
    try {
        if (cart.items.length === 0) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(cart));
        }
    } catch {
        // Storage unavailable (private mode, quota): the cart simply won't survive a refresh.
    }
}
