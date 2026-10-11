<?php /* The brand mark. One definition, reused at every size. */ ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <linearGradient id="gp" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#c084fc"/><stop offset="1" stop-color="#6d28d9"/>
    </linearGradient>
    <linearGradient id="gm" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#f0abfc"/><stop offset=".55" stop-color="#c026d3"/>
      <stop offset="1" stop-color="#7e22ce"/>
    </linearGradient>
    <linearGradient id="gw" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#e9d5ff"/>
    </linearGradient>
    <linearGradient id="gc" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#e9d5ff"/><stop offset="1" stop-color="#9333ea"/>
    </linearGradient>
    <g id="core">
      <path d="M70 50 L70 20 L85 35 L100 9 L115 35 L130 20 L130 50 Z" fill="url(#gc)"/>
      <path d="M100 42 L52 54 L61 122 L100 152 Z" fill="url(#gp)"/>
      <path d="M100 42 L148 54 L139 122 L100 152 Z" fill="url(#gw)"/>
      <path d="M100 52 L61 62 L68 118 L100 141 L132 118 L139 62 Z"
            fill="none" stroke="#2b0f4d" stroke-width="4" opacity=".55"/>
      <path d="M114 76 H92 a9 9 0 0 0 0 18 h16 a9 9 0 0 1 0 18 H86"
            fill="none" stroke="#1c0b33" stroke-width="11" stroke-linecap="square"/>
    </g>
  </defs>
  <!-- full lockup -->
  <symbol id="mark" viewBox="14 2 172 156">
    <path d="M58 60 L22 100 L58 140" fill="none" stroke="url(#gm)" stroke-width="15"/>
    <path d="M142 60 L178 100 L142 140" fill="none" stroke="url(#gm)" stroke-width="15"/>
    <use href="#core"/>
  </symbol>
  <!-- compact variant: below ~48px the chevrons turn to mush, so they come off -->
  <symbol id="mark-sm" viewBox="50 2 100 156">
    <use href="#core"/>
  </symbol>
</svg>
