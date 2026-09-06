/* Client-side trend chart for the Yac Object Cache health panel.
   Terminal-mono look: smoothed 1.25px lines over a dashed grid, square
   hover markers on a dotted crosshair.

   Views are calendar-anchored, not "now minus N": 'today' spans local
   midnight to the next midnight, 'yday' the previous calendar day,
   'week' the last seven calendar days. The PHP side computes those
   boundaries (it knows the site timezone) and ships them in
   data.ranges, so a day line always starts at the left edge of the
   plot instead of hanging off the right end of a week-long axis.
   'today' and 'yday' read the 15-minute columns, 'week' the hourly
   ones; both fall back to whatever resolution actually covers the
   window, and buckets adapt so a view never plots more than ~24
   vertices. A view with no samples inside it borrows the other
   resolution rather than drawing nothing.

   While 'today' is showing, each line ends in a dot that pulses once a
   second: the counters are still moving. The left axis is fitted to the
   observed hit rates so the rate line sits around two-thirds height;
   the right axis tops out below the rate line's lowest point so the
   volume lines can never climb over it. */
( function() {
	'use strict';

	var src = document.getElementById( 'yac-ocache-chart-data' );
	var svg = document.querySelector( '.yac-ocache-chart' );
	if ( ! src || ! svg ) {
		return;
	}
	var data;
	try {
		data = JSON.parse( src.textContent );
	} catch ( e ) {
		return;
	}
	if ( ! data.ranges || ! ( data.min && data.min.t && data.min.t.length ) && ! ( data.hr && data.hr.t && data.hr.t.length ) ) {
		return;
	}

	var wrap  = svg.parentNode;
	var tip   = wrap.querySelector( '.yac-ocache-chart-tip' );
	var cards = {};
	document.querySelectorAll( '.yac-ocache-metric' ).forEach( function( c ) {
		cards[ c.getAttribute( 'data-yac-series' ) || c.getAttribute( 'data-yac-stat' ) ] = c;
	} );
	function setCard( key, text ) {
		if ( cards[ key ] ) {
			cards[ key ].querySelector( '.yac-ocache-metric-val' ).textContent = text;
		}
	}
	/* fails/recycles chips light up only when the window actually logged
	   some, so a quiet cache reads as neutral and any churn catches the eye */
	function setStat( key, n ) {
		var card = cards[ key ];
		if ( ! card ) {
			return;
		}
		card.querySelector( '.yac-ocache-metric-val' ).textContent = fmtK( n );
		card.classList.toggle( 'is-hot', n > 0 );
	}

	var H = 190, PT = 16, PB = 22, PL = 30, PR = 40;
	/* three hues far enough apart to tell 1.25px lines apart on white:
	   green, blue, violet. Amber and red stay reserved for the rate line's
	   own verdict levels so a warning never reads as the misses series.
	   The rate line swaps its own colour per vertex once a bucket crosses
	   the plugin's verdict thresholds (90 / 70) */
	var COLORS = { rate: '#059669', hits: '#2563eb', miss: '#7c3aed' };
	var LEVEL  = { g: '#059669', y: '#f59e0b', r: '#ef4444' };
	var LABELS = { rate: 'Hit rate', hits: 'Hits', miss: 'Misses' };
	var FIELD  = { rate: 'rate', hits: 'h', miss: 'm' };
	var ORDER  = [ 'hits', 'miss', 'rate' ]; /* rate drawn last, on top */
	/* fixed granularity per view: today one point per 15-minute sample,
	   yesterday per hour, the week per 6 hours. The 'min' columns feed
	   today and yesterday, the hourly ones feed the week */
	var VIEWS  = {
		today: { src: 'min', step: 900,   live: true },
		yday:  { src: 'min', step: 3600,  live: false },
		week:  { src: 'hr',  step: 21600, live: false }
	};
	/* all three series always draw; 'on' is kept so the hover/skip guards
	   stay valid if toggling is ever reinstated */
	var state = { view: 'today', on: { rate: true, hits: true, miss: true } };

	function levelOf( r ) {
		return r >= 0.9 ? 'g' : r >= 0.7 ? 'y' : 'r';
	}

	var NS = 'http://www.w3.org/2000/svg';
	function mk( name, attrs, parent ) {
		var node = document.createElementNS( NS, name );
		for ( var k in attrs ) {
			node.setAttribute( k, attrs[ k ] );
		}
		( parent || svg ).appendChild( node );
		return node;
	}
	function txt( x, y, str, attrs, parent ) {
		var a = { x: x, y: y, 'font-size': 10, fill: '#a8a29e' };
		for ( var k in ( attrs || {} ) ) {
			a[ k ] = attrs[ k ];
		}
		var node = mk( 'text', a, parent );
		node.textContent = str;
		return node;
	}
	function fmtK( v ) {
		if ( ! v || v < 0 ) {
			return '0';
		}
		if ( v >= 1e6 ) {
			return ( v / 1e6 ).toFixed( 1 ) + 'M';
		}
		if ( v >= 1e3 ) {
			return ( v / 1e3 ).toFixed( 1 ) + 'K';
		}
		return String( Math.round( v ) );
	}
	function fmtInt( v ) {
		return v.toLocaleString();
	}

	/* the set whose cumulative counters actually bracket [from, to):
	   it needs a sample at or before 'from' to serve as the baseline.
	   'hr' covers the whole week so it always qualifies; 'min' only does
	   for the today view */
	function pickCumulativeSource( from, to ) {
		var cands = [ data.hr, data.min ];
		for ( var i = 0; i < cands.length; i++ ) {
			var s = cands[ i ];
			if ( s && s.f && s.r && s.t && s.t.length && s.t[ 0 ] <= from && s.t[ s.t.length - 1 ] < to ) {
				return s;
			}
		}
		/* fall back to whichever set overlaps the window at all */
		for ( var j = 0; j < cands.length; j++ ) {
			var t = cands[ j ];
			if ( t && t.f && t.r && t.t && t.t.length && t.t[ t.t.length - 1 ] >= from && t.t[ 0 ] < to ) {
				return t;
			}
		}
		return null;
	}

	/* window totals from a cumulative set: counters at the last sample
	   before 'to', minus the baseline from the last bucket ending at or
	   before 'from' (strict: a bucket starting exactly at 'from' carries
	   its counter at the bucket end, already past the window start) */
	function cumulativeWindow( set, from, to ) {
		if ( ! set ) {
			return null;
		}
		var base = null, end = -1;
		for ( var i = 0; i < set.t.length; i++ ) {
			if ( set.t[ i ] < from ) {
				base = i;
			}
		}
		for ( var k = set.t.length - 1; k >= 0; k-- ) {
			if ( set.t[ k ] < to ) {
				end = k;
				break;
			}
		}
		if ( end < 0 ) {
			return null;
		}
		if ( null === base ) {
			/* window opens before any sample: the earliest counter is the
			   best baseline we have */
			base = 0;
		}
		if ( base > end ) {
			base = end;
		}
		return { f: set.f[ end ] - set.f[ base ], r: set.r[ end ] - set.r[ base ] };
	}

	/* vertices for one view: [start, end) clipped to the samples that
	   actually exist, bucketed at the view's fixed granularity */
	function vertices( view ) {
		var range = data.ranges[ view ] || data.ranges.today;
		var from  = range[ 0 ];
		var to    = range[ 1 ];
		var cfg   = VIEWS[ view ] || VIEWS.today;

		/* prefer the configured resolution, fall back to the other one
		   when the view predates it (a young cache has no hourly data,
		   a 7-day view outruns the 48h of minute data) */
		var set = data[ cfg.src ];
		if ( ! set || ! set.t || ! set.t.length ) {
			set = data[ 'min' === cfg.src ? 'hr' : 'min' ];
		}
		if ( ! set || ! set.t || ! set.t.length ) {
			return null;
		}

		/* the window is empty for this resolution -> try the other */
		var first = set.t[ 0 ];
		var last  = set.t[ set.t.length - 1 ];
		if ( last < from || first >= to ) {
			var alt = data[ 'min' === cfg.src ? 'hr' : 'min' ];
			if ( alt && alt.t && alt.t.length && alt.t[ alt.t.length - 1 ] >= from && alt.t[ 0 ] < to ) {
				set = alt;
			} else {
				return { from: from, to: to, pts: [], live: false, fails: 0, recycles: 0 };
			}
		}

		var span = to - from;
		var step = cfg.step;
		var thr  = data.minLookups * step / ( data.interval || 900 );

		var buckets = [];
		var cur     = null;
		for ( var i = 0; i < set.t.length; i++ ) {
			if ( set.t[ i ] < from ) {
				continue;
			}
			if ( set.t[ i ] >= to ) {
				break;
			}
			var b = Math.floor( set.t[ i ] / step ) * step;
			if ( ! cur || cur.t !== b ) {
				cur = { t: b, h: 0, m: 0 };
				buckets.push( cur );
			}
			cur.h += set.h[ i ];
			cur.m += set.m[ i ];
		}

		/* fails/recycles are cumulative, so a window's totals are the
		   difference between the counters bracketing it. Use whichever
		   set reaches back to the window start -- 'min' begins at
		   yesterday midnight, so the yesterday view has no baseline in it
		   and must borrow 'hr' (which spans the whole retained week) */
		var f = 0, r = 0;
		var cum = cumulativeWindow( pickCumulativeSource( from, to ), from, to );
		if ( cum ) {
			f = cum.f;
			r = cum.r;
		}

		var pts = buckets.map( function( bk ) {
			var look = bk.h + bk.m;
			return {
				/* bucket start, not centre: the first vertex of the day
				   then lands exactly on the midnight left edge */
				t: bk.t,
				rate: look >= thr ? bk.h / look : null,
				h: look > 0 ? bk.h : null,
				m: look > 0 ? bk.m : null
			};
		} );

		/* only 'today' is still being written to, and only when the
		   newest sample is in this window */
		var live = !! cfg.live && set.t[ set.t.length - 1 ] >= from;

		return { from: from, to: to, pts: pts, live: live, fails: f, recycles: r };
	}

	function mix( a, b, w ) {
		return [ a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * w, a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * w ];
	}

	/* Catmull-Rom through the vertices as cubic Beziers: round turns,
	   curve still passes every point so hover reads true values;
	   control points clamp to the plot so spikes cannot overshoot.
	   Returns one [p0, c1, c2, p1] tuple per pair of vertices, so a caller
	   can recolour or split mid-segment without rebuilding the curve */
	function beziers( px, top, bot ) {
		var n = px.length, out = [];
		if ( n < 2 ) {
			return out;
		}
		if ( 2 === n ) {
			/* straight run: control points on the line itself */
			return [ [ px[ 0 ], mix( px[ 0 ], px[ 1 ], 1 / 3 ), mix( px[ 0 ], px[ 1 ], 2 / 3 ), px[ 1 ] ] ];
		}
		var clamp = function( v ) {
			return Math.max( top - 1, Math.min( bot + 1, v ) );
		};
		for ( var i = 0; i < n - 1; i++ ) {
			var p0 = px[ Math.max( 0, i - 1 ) ];
			var p1 = px[ i ];
			var p2 = px[ i + 1 ];
			var p3 = px[ Math.min( n - 1, i + 2 ) ];
			out.push( [
				p1,
				[ p1[ 0 ] + ( p2[ 0 ] - p0[ 0 ] ) / 6, clamp( p1[ 1 ] + ( p2[ 1 ] - p0[ 1 ] ) / 6 ) ],
				[ p2[ 0 ] - ( p3[ 0 ] - p1[ 0 ] ) / 6, clamp( p2[ 1 ] - ( p3[ 1 ] - p1[ 1 ] ) / 6 ) ],
				p2
			] );
		}
		return out;
	}

	/* de Casteljau at t = 0.5: the two halves of one cubic */
	function splitHalf( b ) {
		var a = mix( b[ 0 ], b[ 1 ], 0.5 );
		var m = mix( b[ 1 ], b[ 2 ], 0.5 );
		var z = mix( b[ 2 ], b[ 3 ], 0.5 );
		var am = mix( a, m, 0.5 );
		var mz = mix( m, z, 0.5 );
		var mid = mix( am, mz, 0.5 );
		return [ [ b[ 0 ], a, am, mid ], [ mid, mz, z, b[ 3 ] ] ];
	}

	function bezPath( list ) {
		if ( ! list.length ) {
			return '';
		}
		var f = function( p ) {
			return p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 );
		};
		var d = 'M' + f( list[ 0 ][ 0 ] );
		list.forEach( function( b ) {
			d += ' C' + f( b[ 1 ] ) + ' ' + f( b[ 2 ] ) + ' ' + f( b[ 3 ] );
		} );
		return d;
	}

	var view = null;

	function draw() {
		var v = vertices( state.view );
		if ( ! v ) {
			return;
		}
		var pts = v.pts;
		var W   = Math.max( 320, wrap.clientWidth );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
		svg.setAttribute( 'width', W );
		svg.setAttribute( 'height', H );
		while ( svg.firstChild ) {
			svg.removeChild( svg.firstChild );
		}
		if ( tip ) {
			tip.hidden = true;
		}

		/* range totals for the one-line summary above the chart */
		var sumH = 0, sumM = 0;
		pts.forEach( function( p ) {
			sumH += p.h || 0;
			sumM += p.m || 0;
		} );
		setCard( 'rate', ( sumH + sumM ) >= data.minLookups ? ( 100 * sumH / ( sumH + sumM ) ).toFixed( 1 ) + '%' : '—' );
		setCard( 'hits', fmtK( sumH ) );
		setCard( 'miss', fmtK( sumM ) );
		setStat( 'fails', v.fails );
		setStat( 'recycles', v.recycles );

		if ( ! pts.length ) {
			view = null;
			txt( W / 2, H / 2, 'no lookups in this range', { 'text-anchor': 'middle' } );
			return;
		}

		var plotH = H - PT - PB;
		var bot   = PT + plotH;
		var xL    = PL + 6;
		var xR    = W - PR - 6;
		var span  = v.to - v.from;
		var x = function( t ) {
			return +( xL + ( t - v.from ) / span * ( xR - xL ) ).toFixed( 1 );
		};

		/* left axis fits the observed rates and is shifted so the median
		   lands two thirds of the way up the plot -- the rate line rides
		   the upper middle instead of gluing to the ceiling or the floor */
		var rates = [];
		pts.forEach( function( p ) {
			if ( null !== p.rate ) {
				rates.push( p.rate );
			}
		} );
		var lo = 0.9, hi = 1, med = 0.95;
		if ( rates.length ) {
			rates.sort( function( a, b ) { return a - b; } );
			lo  = rates[ 0 ];
			hi  = rates[ rates.length - 1 ];
			med = rates[ Math.floor( rates.length / 2 ) ];
		}
		var w = Math.max( 0.04, ( hi - lo ) * 1.6 );
		/* median two thirds up => floor sits a third of a band below it */
		var floor = med - w / 3;
		if ( floor < 0 ) {
			floor = 0;
		}
		if ( floor + w > 1 ) {
			floor = 1 - w;
		}
		var ceil = floor + w;
		var yRate = function( r ) {
			var c = Math.max( floor, Math.min( ceil, r ) );
			return +( bot - plotH * ( c - floor ) / w ).toFixed( 1 );
		};

		var peak = 1;
		pts.forEach( function( p ) {
			peak = Math.max( peak, p.h || 0, p.m || 0 );
		} );
		/* the volume axis tops out where the rate line dips lowest, so
		   hits/misses never climb above the rate curve */
		var rateLow = null;
		pts.forEach( function( p ) {
			if ( null !== p.rate ) {
				rateLow = Math.max( rateLow === null ? 0 : rateLow, yRate( p.rate ) );
			}
		} );
		var avail = null === rateLow ? plotH : Math.max( plotH * 0.25, Math.min( plotH, bot - rateLow - 10 ) );
		var top   = plotH * peak / avail;
		var yCnt = function( val ) {
			return +( bot - plotH * val / top ).toFixed( 1 );
		};
		var yOf = { rate: yRate, hits: yCnt, miss: yCnt };

		/* shared gridlines: rate ticks left, volume values right */
		[ 0, 0.5, 1 ].forEach( function( f ) {
			var y = +( bot - plotH * f ).toFixed( 1 );
			if ( f > 0 ) {
				mk( 'line', { x1: PL, y1: y, x2: W - PR, y2: y, stroke: '#e7e5e4', 'stroke-width': 1, 'stroke-dasharray': '1 3' } );
			}
			txt( PL - 4, y + 3, String( Math.round( ( floor + w * f ) * 100 ) ), { 'text-anchor': 'end' } );
			txt( W - PR + 4, y + 3, fmtK( top * f ), { 'text-anchor': 'start' } );
		} );
		mk( 'line', { x1: PL, y1: bot, x2: W - PR, y2: bot, stroke: '#d6d3d1', 'stroke-width': 1 } );

		/* clock-aligned ticks: hours within one day, days across the week */
		var singleDay = span <= 86400;
		var tickStep  = singleDay ? 6 * 3600 : 86400;
		var d0 = new Date( v.from * 1000 );
		if ( singleDay ) {
			d0.setMinutes( 0, 0, 0 );
			while ( d0.getHours() % 6 !== 0 ) {
				d0.setTime( d0.getTime() + 3600000 );
			}
		} else {
			d0.setHours( 0, 0, 0, 0 );
			if ( d0.getTime() / 1000 < v.from ) {
				d0.setDate( d0.getDate() + 1 );
			}
		}
		for ( var g = 0; g < 12 && d0.getTime() / 1000 <= v.to; g++ ) {
			var tk = d0.getTime() / 1000;
			txt( x( tk ), H - 7, singleDay
				? d0.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit', hour12: false } )
				: d0.toLocaleDateString( [], { month: 'numeric', day: 'numeric' } ), { 'text-anchor': 'middle' } );
			d0.setTime( d0.getTime() + tickStep * 1000 );
		}

		/* one series as plot-space nodes. A bucket with too few lookups
		   reads null; interior nulls are bridged by linear interpolation
		   between the bracketing samples so the line stays continuous
		   (the bridge's centre is the average of its two ends). Only
		   leading/trailing nulls -- a window edge with no data either side
		   of it -- stay open. */
		function seriesNodes( key ) {
			var vals = pts.map( function( p ) {
				var raw = p[ FIELD[ key ] ];
				return ( null === raw || undefined === raw ) ? null : raw;
			} );
			var i = 0;
			while ( i < vals.length ) {
				if ( null !== vals[ i ] ) {
					i++;
					continue;
				}
				var j = i;
				while ( j < vals.length && null === vals[ j ] ) {
					j++;
				}
				if ( i > 0 && j < vals.length ) {
					var a = vals[ i - 1 ], b = vals[ j ];
					for ( var k = i; k < j; k++ ) {
						vals[ k ] = a + ( b - a ) * ( k - ( i - 1 ) ) / ( j - ( i - 1 ) );
					}
				}
				i = j;
			}
			var nodes = [];
			pts.forEach( function( p, idx ) {
				if ( null === vals[ idx ] ) {
					return;
				}
				nodes.push( {
					x: x( p.t ),
					y: 'rate' === key ? yRate( vals[ idx ] ) : yCnt( vals[ idx ] ),
					lv: 'rate' === key ? levelOf( vals[ idx ] ) : null
				} );
			} );
			return nodes;
		}

		/* smoothed series lines; a lone vertex draws as a square dot. The
		   rate line changes colour at the *midpoint* of any segment whose
		   two ends sit at different verdicts, so a bucket that drops into
		   warning/unhealthy territory tints half the run-in and half the
		   run-out rather than snapping the whole segment */
		var ends = {};
		ORDER.forEach( function( key ) {
			if ( ! state.on[ key ] ) {
				return;
			}
			var nodes = seriesNodes( key );
			if ( ! nodes.length ) {
				return;
			}
			var coords = nodes.map( function( n ) {
				return [ n.x, n.y ];
			} );

			if ( 1 === nodes.length ) {
				mk( 'rect', {
					x: nodes[ 0 ].x - 2, y: nodes[ 0 ].y - 2, width: 4, height: 4,
					fill: 'rate' === key ? LEVEL[ nodes[ 0 ].lv ] : COLORS[ key ]
				} );
			} else if ( 'rate' === key ) {
				beziers( coords, PT, bot ).forEach( function( b, s ) {
					var lvA = nodes[ s ].lv, lvB = nodes[ s + 1 ].lv;
					var stroke = function( d, lv ) {
						mk( 'path', {
							d: d, fill: 'none', stroke: LEVEL[ lv ], 'stroke-width': 1.25,
							'stroke-linejoin': 'round', 'stroke-linecap': 'round'
						} );
					};
					if ( lvA === lvB ) {
						stroke( bezPath( [ b ] ), lvA );
					} else {
						var halves = splitHalf( b );
						stroke( bezPath( [ halves[ 0 ] ] ), lvA );
						stroke( bezPath( [ halves[ 1 ] ] ), lvB );
					}
				} );
			} else {
				mk( 'path', {
					d: bezPath( beziers( coords, PT, bot ) ),
					fill: 'none', stroke: COLORS[ key ], 'stroke-width': 1.25,
					'stroke-linejoin': 'round', 'stroke-linecap': 'round'
				} );
			}

			var lastN = nodes[ nodes.length - 1 ];
			ends[ key ] = { p: [ lastN.x, lastN.y ], color: 'rate' === key ? LEVEL[ lastN.lv ] : COLORS[ key ] };
		} );

		/* 'today' is still counting: pulse a dot at each line's end */
		if ( v.live ) {
			var defs = mk( 'defs', {} );
			var st = document.createElementNS( NS, 'style' );
			st.textContent = '@keyframes yac-ocache-pulse{0%,100%{opacity:1}50%{opacity:.15}}' +
				'.yac-ocache-live{animation:yac-ocache-pulse 1s ease-in-out infinite}';
			defs.appendChild( st );
			Object.keys( ends ).forEach( function( key ) {
				mk( 'circle', {
					cx: ends[ key ].p[ 0 ],
					cy: ends[ key ].p[ 1 ],
					r: 2.5,
					fill: ends[ key ].color,
					'class': 'yac-ocache-live'
				} );
			} );
		}

		/* hover crosshair */
		var hover = mk( 'g', { style: 'display:none' } );
		var vline = mk( 'line', { y1: PT, y2: bot, stroke: '#a8a29e', 'stroke-width': 1, 'stroke-dasharray': '2 3' }, hover );
		var dots  = {};
		ORDER.forEach( function( key ) {
			dots[ key ] = mk( 'rect', { width: 6, height: 6, fill: COLORS[ key ], stroke: '#fff', 'stroke-width': 1.5 }, hover );
		} );
		mk( 'rect', { x: 0, y: 0, width: W, height: H, fill: 'transparent' } );

		view = { pts: pts, x: x, yOf: yOf, hover: hover, vline: vline, dots: dots, W: W };
	}

	function onMove( ev ) {
		if ( ! view || ! tip ) {
			return;
		}
		var rect  = svg.getBoundingClientRect();
		var scale = rect.width / view.W;
		var vx    = ( ev.clientX - rect.left ) / scale;
		var best = 0, bestD = Infinity;
		view.pts.forEach( function( p, i ) {
			var d = Math.abs( view.x( p.t ) - vx );
			if ( d < bestD ) {
				bestD = d;
				best = i;
			}
		} );
		var p  = view.pts[ best ];
		var px = view.x( p.t );
		view.hover.style.display = '';
		view.vline.setAttribute( 'x1', px );
		view.vline.setAttribute( 'x2', px );

		var when = new Date( p.t * 1000 );
		var head = when.toLocaleDateString( [], { month: 'short', day: 'numeric' } ) + ' ' +
			when.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit', hour12: false } );
		var rows = '';
		ORDER.forEach( function( key ) {
			var val = p[ FIELD[ key ] ];
			var show = state.on[ key ] && null !== val;
			view.dots[ key ].style.display = show ? '' : 'none';
			if ( show ) {
				var cy = view.yOf[ key ]( val );
				var color = 'rate' === key ? LEVEL[ levelOf( val ) ] : COLORS[ key ];
				view.dots[ key ].setAttribute( 'fill', color );
				view.dots[ key ].setAttribute( 'x', px - 3 );
				view.dots[ key ].setAttribute( 'y', cy - 3 );
				rows += '<div class="row"><i style="background:' + color + '"></i>' + LABELS[ key ] +
					'<b>' + ( 'rate' === key ? ( val * 100 ).toFixed( 1 ) + '%' : fmtInt( val ) ) + '</b></div>';
			}
		} );
		tip.innerHTML = '<div class="d">' + head + '</div>' + rows;
		tip.hidden = false;

		var wrapRect = wrap.getBoundingClientRect();
		var left = ( rect.left - wrapRect.left ) + px * scale + 16;
		if ( left + tip.offsetWidth > wrapRect.width - 4 ) {
			left = ( rect.left - wrapRect.left ) + px * scale - tip.offsetWidth - 16;
		}
		tip.style.left = Math.max( 4, left ) + 'px';
		tip.style.top = Math.max( 4, Math.min( ev.clientY - wrapRect.top - tip.offsetHeight / 2, wrapRect.height - tip.offsetHeight - 4 ) ) + 'px';
	}
	function onLeave() {
		if ( view ) {
			view.hover.style.display = 'none';
		}
		if ( tip ) {
			tip.hidden = true;
		}
	}
	svg.addEventListener( 'mousemove', onMove );
	svg.addEventListener( 'mouseleave', onLeave );

	document.querySelectorAll( '[data-yac-range]' ).forEach( function( btn ) {
		btn.classList.toggle( 'is-active', btn.getAttribute( 'data-yac-range' ) === state.view );
		btn.setAttribute( 'aria-pressed', btn.getAttribute( 'data-yac-range' ) === state.view ? 'true' : 'false' );
		btn.addEventListener( 'click', function() {
			var next = btn.getAttribute( 'data-yac-range' );
			if ( ! data.ranges[ next ] || next === state.view ) {
				return;
			}
			state.view = next;
			document.querySelectorAll( '[data-yac-range]' ).forEach( function( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			draw();
		} );
	} );

	var raf = 0;
	window.addEventListener( 'resize', function() {
		cancelAnimationFrame( raf );
		raf = requestAnimationFrame( draw );
	} );

	draw();
} )();
