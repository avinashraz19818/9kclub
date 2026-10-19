const t=`<!-- receivedDialog 底部的关闭钮。稿面(19906:122582 icon_close_03)三条描边同色，
     绑的是 text_btn_main，照此实现。
     改动前是两套来源:圆环写死 #D4E8FF、X 走 --text_primary。
     ⚠️ red 主题没定义 --text_btn_main，不给兜底的话 stroke 会落到初始值 none、图标整个消失，
     故回退到它定义了的 --text_color_L4(#FFFFFF);两级都是变量，不写死色值。 -->
<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60" fill="none">
  <path d="M30 3C44.9117 3 57 15.0883 57 30C57 44.9117 44.9117 57 30 57C15.0883 57 3 44.9117 3 30C3 15.0883 15.0883 3 30 3Z" stroke="var(--text_btn_main, var(--text_color_L4))" stroke-width="4" stroke-linejoin="round"/>
  <path d="M43 17L17 43" stroke="var(--text_btn_main, var(--text_color_L4))" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M17 17L43 43" stroke="var(--text_btn_main, var(--text_color_L4))" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
`;export{t as default};
