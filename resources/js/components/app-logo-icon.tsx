import type { SVGAttributes } from 'react';

/** Family tree mark: a root ancestor branching into two generations. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                d="M20 9v8M20 17h-11v6M20 17h11v6M9 27v2M31 27v2M20 17v10"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <circle cx="20" cy="6" r="4" />
            <circle cx="9" cy="25" r="3.5" />
            <circle cx="20" cy="30" r="3.5" />
            <circle cx="31" cy="25" r="3.5" />
            <circle cx="9" cy="34" r="2.5" />
            <circle cx="31" cy="34" r="2.5" />
        </svg>
    );
}
