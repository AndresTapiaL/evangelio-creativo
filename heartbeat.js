(function(){
    /* se lanza en TODAS las páginas donde exista un token */
    const TEN_MIN = 600_000;           // 10 min en ms
    let token = localStorage.getItem('token');
    if (!token) return;                // sin login => nada que hacer
  
    async function beat(){
      token = localStorage.getItem('token');   // refresco x si el user cambió
      if (!token) return;
      try {
        const r = await fetch('validar_token.php?hb=1', {
          headers: { 'Authorization': 'Bearer '+token,
                     'X-Heartbeat' : '1' }
        });
        if (r.status === 401) throw new Error('TokenNoValido');
        const d = await r.json();
        if (!d.ok) throw new Error('TokenNoValido');
        /* ok silencioso */
      } catch (e) {
        if (e.message === 'TokenNoValido'){
          localStorage.clear();
          location.replace('login.html');
        }
        /* errores de red se ignoran: re-intentará en el próximo ciclo */
      }
    }
  
    beat();                 // primer latido inmediato
    setInterval(beat, TEN_MIN);
  })();
  