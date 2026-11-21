import { useEffect, useRef, useState } from 'react';
import { formatDate } from '@/lib/format';

export type DailyPoint = { date: string; value: number; note?: string };

type Props = {
    data: DailyPoint[];
    /** Formats values for the axis and tooltip. */
    format: (value: number) => string;
    /** Short axis label (e.g. "1,2 jt"). */
    formatAxis: (value: number) => string;
    label: string;
    height?: number;
};

const PAD = { top: 12, right: 8, bottom: 22, left: 52 };
const shortDate = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
});

/** Rounded "nice" step for about three gridlines. */
function niceStep(span: number): number {
    if (span <= 0) {
        return 1;
    }

    const raw = span / 3;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const residual = raw / magnitude;
    const nice = residual > 5 ? 10 : residual > 2 ? 5 : residual > 1 ? 2 : 1;

    return nice * magnitude;
}

/**
 * One series of daily totals as thin bars (negative values hang below the zero line), with
 * a hover tooltip per day and a hidden table for screen readers. Plain SVG, no chart library.
 */
export function DailyBarChart({
    data,
    format,
    formatAxis,
    label,
    height = 200,
}: Props) {
    const [hover, setHover] = useState<number | null>(null);
    // Draw at the container's real pixel width so text stays at its real size.
    const boxRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(640);

    useEffect(() => {
        const el = boxRef.current;

        if (!el) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.max(240, Math.round(entry.contentRect.width))),
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, []);
    const innerW = width - PAD.left - PAD.right;
    const innerH = height - PAD.top - PAD.bottom;

    const values = data.map((d) => d.value);
    const step = niceStep(Math.max(...values, 0) - Math.min(...values, 0));
    const max = Math.max(step, Math.ceil(Math.max(...values, 0) / step) * step);
    const min = Math.min(0, Math.floor(Math.min(...values, 0) / step) * step);
    const y = (v: number) => PAD.top + ((max - v) / (max - min)) * innerH;
    const slot = innerW / Math.max(data.length, 1);
    const barW = Math.max(2, Math.min(18, slot - 2)); // 2px surface gap between bars
    const ticks: number[] = [];

    for (let t = min; t <= max + step / 2; t += step) {
        ticks.push(t);
    }

    const labelEvery = Math.ceil(
        data.length / Math.max(2, Math.floor(innerW / 70)),
    );
    const active = hover === null ? null : data[hover];

    return (
        <div ref={boxRef} className="relative">
            <svg
                width={width}
                height={height}
                viewBox={`0 0 ${width} ${height}`}
                className="block"
                role="img"
                aria-label={label}
                onMouseLeave={() => setHover(null)}
            >
                {ticks.map((t) => (
                    <g key={t}>
                        <line
                            x1={PAD.left}
                            x2={width - PAD.right}
                            y1={y(t)}
                            y2={y(t)}
                            className={
                                t === 0 ? 'stroke-border' : 'stroke-border/50'
                            }
                            strokeWidth={1}
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(t)}
                            textAnchor="end"
                            dominantBaseline="middle"
                            className="fill-muted-foreground text-[11px]"
                        >
                            {formatAxis(t)}
                        </text>
                    </g>
                ))}

                {data.map((d, i) => {
                    const cx = PAD.left + slot * i + slot / 2;
                    const top = y(Math.max(d.value, 0));
                    const h = Math.abs(y(d.value) - y(0));
                    const r = Math.min(4, barW / 2, h / 2);

                    return (
                        <g key={d.date}>
                            {h > 0 && (
                                <path
                                    d={
                                        d.value >= 0
                                            ? `M${cx - barW / 2},${top + h} V${top + r} q0,-${r} ${r},-${r} H${cx + barW / 2 - r} q${r},0 ${r},${r} V${top + h} Z`
                                            : `M${cx - barW / 2},${top} V${top + h - r} q0,${r} ${r},${r} H${cx + barW / 2 - r} q${r},0 ${r},-${r} V${top} Z`
                                    }
                                    className="fill-chart-2"
                                    opacity={
                                        hover === null || hover === i ? 1 : 0.45
                                    }
                                />
                            )}
                            {i % labelEvery === 0 && (
                                <text
                                    x={cx}
                                    y={height - 6}
                                    textAnchor="middle"
                                    className="fill-muted-foreground text-[11px]"
                                >
                                    {shortDate.format(
                                        new Date(`${d.date}T00:00:00+07:00`),
                                    )}
                                </text>
                            )}
                            {/* Hit target: the whole column, wider than the bar. */}
                            <rect
                                x={PAD.left + slot * i}
                                y={PAD.top}
                                width={slot}
                                height={innerH}
                                fill="transparent"
                                onMouseEnter={() => setHover(i)}
                                onFocus={() => setHover(i)}
                            />
                        </g>
                    );
                })}
            </svg>

            {active && hover !== null && (
                <div
                    className="pointer-events-none absolute top-0 z-10 rounded-md border bg-popover px-2.5 py-1.5 text-xs whitespace-nowrap shadow-md"
                    style={(() => {
                        const pct =
                            ((PAD.left + slot * hover + slot / 2) / width) *
                            100;
                        const shift =
                            pct > 70 ? '-100%' : pct < 30 ? '0' : '-50%';

                        return {
                            left: `${pct}%`,
                            transform: `translateX(${shift})`,
                        };
                    })()}
                >
                    <div className="text-muted-foreground">
                        {formatDate(active.date)}
                    </div>
                    <div className="font-semibold tabular-nums">
                        {format(active.value)}
                    </div>
                    {active.note && (
                        <div className="text-muted-foreground">
                            {active.note}
                        </div>
                    )}
                </div>
            )}

            <table className="sr-only">
                <caption>{label}</caption>
                <tbody>
                    {data.map((d) => (
                        <tr key={d.date}>
                            <th scope="row">{formatDate(d.date)}</th>
                            <td>{format(d.value)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
