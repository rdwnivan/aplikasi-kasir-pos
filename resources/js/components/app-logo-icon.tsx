import type { SVGAttributes } from 'react';

/** Shopping-bag mark for the app (filled, so it follows `fill-current`). */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 42" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M13 14v-4a7 7 0 0 1 14 0v4h-4v-4a3 3 0 0 0-6 0v4h-4ZM4 16a2 2 0 0 1 2-2h28a2 2 0 0 1 2 2v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V16Zm9.5 9.5a1.5 1.5 0 0 0 0 3h13a1.5 1.5 0 0 0 0-3h-13Z"
            />
        </svg>
    );
}
