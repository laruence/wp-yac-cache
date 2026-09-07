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

	var wrap = svg.parentNode;
	var tip  = wrap.querySelector( '.yac-ocache-chart-tip' );
	var cards = {};
	document.querySelectorAll( '.yac-ocache-metric[data-yac-series]' ).forEach( function( card ) {
		cards[ card.getAttribute( 'data-yac-series' ) ] = card;
	} );

	var H = 190, PT = 16, PB = 22, PL = 30, PR = 40;
	var COLORS = {
		rate: '#198038', hits: '#3675b5', miss: '#b64f7b', kicks: '#6955a3',
		recycles: '#c88f00', fails: '#a94a18'
	};
	var STATUS = {
		healthy: { color: '#198038', tint: '#edf9ef', icon: '●', label: 'Healthy' },
		warning: { color: '#9a6700', tint: '#fff8db', icon: '▲', label: 'Attention' },
		critical: { color: '#da1e28', tint: '#fff1f1', icon: '◆', label: 'Critical' }
	};
	var CHROME = { text: '#6f6f6f', grid: '#e5e5e5', axis: '#a8a8a8', hover: '#6f6f6f', surface: '#fcfcfb' };
	var LABELS = {
		rate: 'Hit rate', hits: 'Hits', miss: 'Misses', kicks: 'Kicks',
		recycles: 'Recycles', fails: 'Fails'
	};
	var FIELD  = { rate: 'rate', hits: 'h', miss: 'm', kicks: 'k', recycles: 'r', fails: 'f' };
	var LINES  = [ 'hits', 'miss', 'kicks', 'rate' ];
	var TIP_ORDER = [ 'rate', 'hits', 'miss', 'kicks' ];
	var EVENTS = [ 'recycles', 'fails' ];
	var VIEWS  = {
		today: { src: 'min', step: 1800, live: true },
		yday:  { src: 'min', step: 3600, live: false },
		week:  { src: 'hr', step: 21600, live: false }
	};
	var state = {
		view: 'today',
		on: { hits: true, miss: true, kicks: true, recycles: true, fails: true },
		pinnedIndex: null
	};

	function levelOf( rate ) {
		return rate >= 0.9 ? 'healthy' : rate >= 0.7 ? 'warning' : 'critical';
	}
	function setCard( key, text ) {
		if ( cards[ key ] ) {
			cards[ key ].querySelector( '.yac-ocache-metric-val' ).textContent = text;
		}
	}
	function setRateCard( rate, enough ) {
		var card = cards.rate;
		if ( ! card ) {
			return;
		}
		card.querySelector( '.yac-ocache-metric-val' ).textContent = enough ? ( rate * 100 ).toFixed( 1 ) + '%' : '—';
		card.classList.remove( 'is-healthy', 'is-warning', 'is-critical', 'is-warmup' );
		card.classList.add( enough ? 'is-' + levelOf( rate ) : 'is-warmup' );
	}
	function fmtK( value ) {
		if ( ! value || value < 0 ) {
			return '0';
		}
		if ( value >= 1e6 ) {
			return ( value / 1e6 ).toFixed( 1 ) + 'M';
		}
		if ( value >= 1e3 ) {
			return ( value / 1e3 ).toFixed( 1 ) + 'K';
		}
		return String( Math.round( value ) );
	}
	function fmtInt( value ) {
		return value.toLocaleString();
	}

	var NS = 'http://www.w3.org/2000/svg';
	function mk( name, attrs, parent ) {
		var node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( function( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		( parent || svg ).appendChild( node );
		return node;
	}
	function txt( x, y, text, attrs, parent ) {
		var node = mk( 'text', Object.assign( { x: x, y: y, 'font-size': 10, fill: CHROME.text }, attrs || {} ), parent );
		node.textContent = text;
		return node;
	}
	/* Monotone cubic Bezier controls keep the line fluid without overshooting
	 * observed values. Flat segments zero both tangents; steep controls are
	 * normalised before emitting the C commands. */
	function bezPath( points ) {
		if ( ! Array.isArray( points ) || ! points.length ) {
			return '';
		}
		var safe = [];
		for ( var i = 0; i < points.length; i++ ) {
			var point = points[ i ];
			if ( ! point || ! Number.isFinite( point[ 0 ] ) || ! Number.isFinite( point[ 1 ] ) ) {
				return '';
			}
			var last = safe[ safe.length - 1 ];
			if ( ! last || point[ 0 ] > last[ 0 ] ) {
				safe.push( [ point[ 0 ], point[ 1 ] ] );
			} else if ( point[ 0 ] === last[ 0 ] ) {
				last[ 1 ] = point[ 1 ];
			} else {
				return '';
			}
		}
		var d = 'M' + safe[ 0 ][ 0 ].toFixed( 1 ) + ' ' + safe[ 0 ][ 1 ].toFixed( 1 );
		if ( 1 === safe.length ) {
			return d;
		}
		var slopes = [], tangents = [];
		for ( i = 0; i < safe.length - 1; i++ ) {
			slopes.push( ( safe[ i + 1 ][ 1 ] - safe[ i ][ 1 ] ) / ( safe[ i + 1 ][ 0 ] - safe[ i ][ 0 ] ) );
		}
		for ( i = 0; i < safe.length; i++ ) {
			tangents.push( 0 === i ? slopes[ 0 ] : i === safe.length - 1 ? slopes[ slopes.length - 1 ] : ( slopes[ i - 1 ] + slopes[ i ] ) / 2 );
		}
		for ( i = 0; i < slopes.length; i++ ) {
			if ( 0 === slopes[ i ] ) {
				tangents[ i ] = 0;
				tangents[ i + 1 ] = 0;
				continue;
			}
			var a = tangents[ i ] / slopes[ i ], b = tangents[ i + 1 ] / slopes[ i ];
			var magnitude = a * a + b * b;
			if ( magnitude > 9 ) {
				var scale = 3 / Math.sqrt( magnitude );
				tangents[ i ] = scale * a * slopes[ i ];
				tangents[ i + 1 ] = scale * b * slopes[ i ];
			}
		}
		for ( i = 0; i < safe.length - 1; i++ ) {
			var start = safe[ i ], end = safe[ i + 1 ], width = ( end[ 0 ] - start[ 0 ] ) / 3;
			d += ' C' + ( start[ 0 ] + width ).toFixed( 1 ) + ' ' + ( start[ 1 ] + tangents[ i ] * width ).toFixed( 1 )
				+ ' ' + ( end[ 0 ] - width ).toFixed( 1 ) + ' ' + ( end[ 1 ] - tangents[ i + 1 ] * width ).toFixed( 1 )
				+ ' ' + end[ 0 ].toFixed( 1 ) + ' ' + end[ 1 ].toFixed( 1 );
		}
		return d;
	}

	function addDelta( bucket, field, value ) {
		/* Missing intervals are skipped; any observed delta remains additive.
		 * A bucket with no observed delta at all stays unavailable. */
		if ( null === value || undefined === value ) {
			return;
		}
		bucket[ field ] += value;
		bucket[ field + 'Known' ] = true;
	}

	function vertices( viewName ) {
		var range = data.ranges[ viewName ] || data.ranges.today;
		var from = range[ 0 ], to = range[ 1 ];
		var cfg = VIEWS[ viewName ] || VIEWS.today;
		var set = data[ cfg.src ];
		var zeroFill = 'yday' === viewName || 'week' === viewName;
		if ( ! set || ! set.t || ! set.t.length ) {
			set = data[ 'min' === cfg.src ? 'hr' : 'min' ];
		}
		if ( ! set || ! set.t || ! set.t.length ) {
			return { from: from, to: to, pts: [], live: false };
		}
		if ( set.t[ set.t.length - 1 ] < from || set.t[ 0 ] >= to ) {
			var alt = data[ 'min' === cfg.src ? 'hr' : 'min' ];
			if ( alt && alt.t && alt.t.length && alt.t[ alt.t.length - 1 ] >= from && alt.t[ 0 ] < to ) {
				set = alt;
			} else if ( ! zeroFill ) {
				return { from: from, to: to, pts: [], live: false };
			} else {
				set = { t: [], h: [], m: [], k: [], f: [], r: [] };
			}
		}

		var step = cfg.step;
		var threshold = data.minLookups * step / ( data.interval || 900 );
		var buckets = [];
		var byTime = {};
		function makeBucket( time ) {
			return {
				t: time, h: 0, m: 0, k: 0, f: 0, r: 0, real: false,
				kKnown: false, fKnown: false, rKnown: false
			};
		}
		if ( zeroFill ) {
			for ( var fill = from; fill < to; fill += step ) {
				var initial = makeBucket( fill );
				buckets.push( initial );
				byTime[ fill ] = initial;
			}
		}
		for ( var i = 0; i < set.t.length; i++ ) {
			if ( set.t[ i ] < from || set.t[ i ] >= to ) {
				continue;
			}
			var bucketTime = from + Math.floor( ( set.t[ i ] - from ) / step ) * step;
			var bucket = byTime[ bucketTime ];
			if ( ! bucket ) {
				bucket = makeBucket( bucketTime );
				buckets.push( bucket );
				byTime[ bucketTime ] = bucket;
			}
			bucket.real = true;
			bucket.h += set.h[ i ] || 0;
			bucket.m += set.m[ i ] || 0;
			addDelta( bucket, 'k', set.k ? set.k[ i ] : null );
			addDelta( bucket, 'f', set.f ? set.f[ i ] : null );
			addDelta( bucket, 'r', set.r ? set.r[ i ] : null );
		}
		buckets.sort( function( a, b ) { return a.t - b.t; } );

		var pts = buckets.map( function( bucket ) {
			var lookups = bucket.h + bucket.m;
			return {
				t: bucket.t,
				rate: zeroFill && 0 === lookups ? 0 : ( lookups >= threshold ? bucket.h / lookups : null ),
				h: zeroFill ? bucket.h : ( lookups > 0 ? bucket.h : null ),
				m: zeroFill ? bucket.m : ( lookups > 0 ? bucket.m : null ),
				k: bucket.kKnown ? bucket.k : null,
				f: bucket.fKnown ? bucket.f : null,
				r: bucket.rKnown ? bucket.r : null,
				real: bucket.real
			};
		} );
		return {
			from: from, to: to, pts: pts, step: step,
			live: !! cfg.live && set.t.length && set.t[ set.t.length - 1 ] >= from
		};
	}

	var chartView = null;
	function drawLine( key, nodes ) {
		var segments = [], segment = [];
		nodes.forEach( function( node ) {
			if ( node ) {
				segment.push( node );
			} else if ( segment.length ) {
				segments.push( segment.splice( 0 ) );
			}
		} );
		if ( segment.length ) {
			segments.push( segment );
		}
		segments.forEach( function( run ) {
			if ( 1 === run.length ) {
				mk( 'circle', { cx: run[ 0 ].x, cy: run[ 0 ].y, r: 3.5, fill: COLORS[ key ], stroke: CHROME.surface, 'stroke-width': 1.5 } );
				mk( 'circle', {
					cx: run[ 0 ].x, cy: run[ 0 ].y, r: 9, fill: 'transparent',
					'data-yac-line-hit': key
				} );
				return;
			}
			var path = bezPath( run.map( function( node ) { return [ node.x, node.y ]; } ) );
			mk( 'path', {
				d: path, fill: 'none', stroke: COLORS[ key ], 'stroke-width': 1.25,
				'stroke-linejoin': 'round', 'stroke-linecap': 'round'
			} );
			mk( 'path', {
				d: path, fill: 'none', stroke: 'transparent', 'stroke-width': 12,
				'stroke-linejoin': 'round', 'stroke-linecap': 'round',
				'pointer-events': 'stroke', 'data-yac-line-hit': key
			} );
		} );
		return segments.length ? segments[ segments.length - 1 ][ segments[ segments.length - 1 ].length - 1 ] : null;
	}

	function draw() {
		state.pinnedIndex = null;
		if ( tip ) {
			tip.hidden = true;
		}
		var chart = vertices( state.view );
		var pts = chart.pts;
		var W = Math.max( 320, wrap.clientWidth );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
		svg.setAttribute( 'width', W );
		svg.setAttribute( 'height', H );
		svg.setAttribute( 'tabindex', '0' );
		while ( svg.firstChild ) {
			svg.removeChild( svg.firstChild );
		}
		if ( ! pts.length ) {
			txt( W / 2, H / 2, 'no lookups in this range', { 'text-anchor': 'middle' } );
			chartView = null;
			return;
		}

		var sumH = 0, sumM = 0, known = { k: false, f: false, r: false }, sums = { k: 0, f: 0, r: 0 };
		pts.forEach( function( point ) {
			sumH += point.h || 0;
			sumM += point.m || 0;
			[ 'k', 'f', 'r' ].forEach( function( field ) {
				if ( null !== point[ field ] && undefined !== point[ field ] ) {
					known[ field ] = true;
					sums[ field ] += point[ field ];
				}
			} );
		} );
		var lookups = sumH + sumM;
		var averageRate = lookups >= data.minLookups ? sumH / lookups : null;
		setRateCard( averageRate, null !== averageRate );
		setCard( 'hits', fmtK( sumH ) );
		setCard( 'miss', fmtK( sumM ) );
		setCard( 'kicks', known.k ? fmtK( sums.k ) : '—' );
		setCard( 'fails', known.f ? fmtK( sums.f ) : '—' );
		setCard( 'recycles', known.r ? fmtK( sums.r ) : '—' );

		var plotH = H - PT - PB, bot = PT + plotH, xL = PL + 6, xR = W - PR - 6;
		var separatorY = PT + plotH * 0.5;
		var rateTop = PT, rateBottom = separatorY, rateH = rateBottom - rateTop;
		var countTop = separatorY, countH = bot - countTop;
		var span = chart.to - chart.from;
		var x = function( time ) { return +( xL + ( time - chart.from ) / span * ( xR - xL ) ).toFixed( 1 ); };
		var rates = pts.filter( function( point ) { return point.real && null !== point.rate; } ).map( function( point ) { return point.rate; } );
		if ( null !== averageRate ) {
			rates.push( averageRate );
		}
		var lo = rates.length ? Math.min.apply( null, rates ) : 0.9;
		var hi = rates.length ? Math.max.apply( null, rates ) : 1;
		/* Keep the two scales close without allowing them to cross. */
		var band = Math.max( 0.04, ( hi - lo ) * 1.25 );
		var floor = lo - band * 0.1;
		if ( floor < 0 ) {
			floor = 0;
		}
		if ( floor + band > 1 ) {
			floor = Math.max( 0, 1 - band );
		}
		var yRate = function( value ) {
			var clamped = Math.max( floor, Math.min( floor + band, value ) );
			return +( rateBottom - rateH * ( clamped - floor ) / band ).toFixed( 1 );
		};
		var peak = 1;
		pts.forEach( function( point ) {
			if ( state.on.hits ) { peak = Math.max( peak, point.h || 0 ); }
			if ( state.on.miss ) { peak = Math.max( peak, point.m || 0 ); }
			if ( state.on.kicks ) { peak = Math.max( peak, point.k || 0 ); }
		} );
		var yCount = function( value ) { return +( bot - countH * value / peak ).toFixed( 1 ); };
		var yOf = { rate: yRate, hits: yCount, miss: yCount, kicks: yCount };

		[ 0, 0.5, 1 ].forEach( function( fraction ) {
			var rateY = +( rateBottom - rateH * fraction ).toFixed( 1 );
			var countY = +( bot - countH * fraction ).toFixed( 1 );
			if ( fraction > 0 ) {
				mk( 'line', { x1: PL, y1: rateY, x2: W - PR, y2: rateY, stroke: CHROME.grid, 'stroke-width': 1 } );
			}
			if ( fraction < 1 ) {
				mk( 'line', { x1: PL, y1: countY, x2: W - PR, y2: countY, stroke: CHROME.grid, 'stroke-width': 1 } );
			}
			txt( PL - 4, rateY + 3, String( Math.round( ( floor + band * fraction ) * 100 ) ), { 'text-anchor': 'end' } );
			txt( W - PR + 4, countY + 3, fmtK( peak * fraction ), { 'text-anchor': 'start' } );
		} );
		mk( 'line', { x1: PL, y1: separatorY, x2: W - PR, y2: separatorY, stroke: CHROME.grid, 'stroke-width': 1 } );
		mk( 'line', { x1: PL, y1: bot, x2: W - PR, y2: bot, stroke: CHROME.axis, 'stroke-width': 1 } );
		var averageBadge = null;
		if ( null !== averageRate ) {
			var averageY = yRate( averageRate );
			var averageStatus = STATUS[ levelOf( averageRate ) ];
			mk( 'line', {
				x1: xL, y1: averageY, x2: xR, y2: averageY,
				stroke: averageStatus.color, 'stroke-width': 1,
				'stroke-dasharray': '4 4', opacity: 0.55
			} );
			averageBadge = { y: averageY, status: averageStatus, rate: averageRate };
		}

		var singleDay = span <= 86400, tickStep = singleDay ? 6 * 3600 : 86400;
		var tick = new Date( chart.from * 1000 );
		if ( singleDay ) {
			tick.setMinutes( 0, 0, 0 );
			while ( tick.getHours() % 6 !== 0 ) { tick.setTime( tick.getTime() + 3600000 ); }
		} else {
			tick.setHours( 0, 0, 0, 0 );
			if ( tick.getTime() / 1000 < chart.from ) { tick.setDate( tick.getDate() + 1 ); }
		}
		for ( var t = 0; t < 12 && tick.getTime() / 1000 <= chart.to; t++ ) {
			txt( x( tick.getTime() / 1000 ), H - 7, singleDay
				? tick.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit', hour12: false } )
				: tick.toLocaleDateString( [], { month: 'numeric', day: 'numeric' } ), { 'text-anchor': 'middle' } );
			tick.setTime( tick.getTime() + tickStep * 1000 );
		}

		var ends = {};
		LINES.forEach( function( key ) {
			if ( 'rate' !== key && ! state.on[ key ] ) {
				return;
			}
			var nodes = pts.map( function( point ) {
				var value = point[ FIELD[ key ] ];
				return null === value || undefined === value ? null : { x: x( point.t ), y: yOf[ key ]( value ) };
			} );
			var last = drawLine( key, nodes );
			if ( last ) {
				ends[ key ] = last;
			}
		} );

		var eventLanes = { recycles: bot - countH * 0.22, fails: bot - countH * 0.45 };
		EVENTS.forEach( function( key ) {
			if ( ! state.on[ key ] ) {
				return;
			}
			pts.forEach( function( point ) {
				var value = point[ FIELD[ key ] ];
				if ( null === value || undefined === value || value <= 0 ) {
					return;
				}
				var cx = x( point.t ), cy = eventLanes[ key ];
				if ( 'fails' === key ) {
					mk( 'polygon', { points: cx + ',' + ( cy - 4.5 ) + ' ' + ( cx + 4.5 ) + ',' + cy + ' ' + cx + ',' + ( cy + 4.5 ) + ' ' + ( cx - 4.5 ) + ',' + cy, fill: COLORS[ key ], stroke: CHROME.surface, 'stroke-width': 1.5 } );
				} else {
					mk( 'circle', { cx: cx, cy: cy, r: 3.5, fill: COLORS[ key ], stroke: CHROME.surface, 'stroke-width': 1.5 } );
				}
			} );
		} );

		if ( chart.live ) {
			var defs = mk( 'defs', {} );
			var pulseStyle = document.createElementNS( NS, 'style' );
			pulseStyle.textContent = '@keyframes yac-ocache-pulse{0%,100%{opacity:1}50%{opacity:.18}}' +
				'.yac-ocache-live{animation:yac-ocache-pulse 1s ease-in-out infinite}';
			defs.appendChild( pulseStyle );
			Object.keys( ends ).forEach( function( key ) {
				mk( 'circle', {
					cx: ends[ key ].x, cy: ends[ key ].y, r: 3.5,
					fill: COLORS[ key ], stroke: CHROME.surface, 'stroke-width': 1.5,
					'class': 'yac-ocache-live'
				} );
			} );
		}

		var hover = mk( 'g', { style: 'display:none', 'pointer-events': 'none' } );
		var vline = mk( 'line', { y1: PT, y2: bot, stroke: CHROME.hover, 'stroke-width': 1, 'stroke-dasharray': '2 3' }, hover );
		var dots = {};
		LINES.forEach( function( key ) {
			dots[ key ] = mk( 'circle', { r: 3.5, fill: COLORS[ key ], stroke: CHROME.surface, 'stroke-width': 1.5 }, hover );
		} );
		mk( 'rect', { x: 0, y: 0, width: W, height: H, fill: 'transparent', 'pointer-events': 'none' } );

		if ( averageBadge ) {
			var averageStatus = averageBadge.status;
			var badge = mk( 'g', { 'aria-hidden': 'true', 'pointer-events': 'none' } );
			var badgeHeight = 17;
			var badgeTop = averageBadge.y - badgeHeight / 2;
			var badgeText = txt(
				0,
				averageBadge.y + 3.5,
				averageStatus.icon + ' ' + averageStatus.label + ' · ' + ( averageBadge.rate * 100 ).toFixed( 1 ) + '%',
				{ style: 'fill:' + averageStatus.color, 'font-weight': 500 },
				badge
			);
			var badgeWidth = Math.ceil( badgeText.getComputedTextLength() ) + 12;
			var badgeX = Math.max( xL, xR - badgeWidth );
			badgeText.setAttribute( 'x', badgeX + 6 );
			var badgeBack = mk( 'rect', {
				x: badgeX, y: badgeTop, width: badgeWidth, height: badgeHeight,
				rx: 3, fill: averageStatus.tint, stroke: averageStatus.color,
				'stroke-width': 0.75
			}, badge );
			badge.insertBefore( badgeBack, badgeText );
		}
		chartView = { pts: pts, x: x, yOf: yOf, hover: hover, vline: vline, dots: dots, W: W, step: chart.step, to: chart.to };
	}

	function pointIndex( event ) {
		if ( ! chartView ) {
			return null;
		}
		var rect = svg.getBoundingClientRect();
		var px = ( event.clientX - rect.left ) / ( rect.width / chartView.W );
		var best = 0, distance = Infinity;
		chartView.pts.forEach( function( point, index ) {
			var next = Math.abs( chartView.x( point.t ) - px );
			if ( next < distance ) {
				distance = next;
				best = index;
			}
		} );
		return best;
	}
	function showPoint( index, event ) {
		if ( null === index || ! chartView || ! tip ) {
			return;
		}
		var point = chartView.pts[ index ], px = chartView.x( point.t );
		chartView.hover.style.display = '';
		chartView.vline.setAttribute( 'x1', px );
		chartView.vline.setAttribute( 'x2', px );
		var date = new Date( point.t * 1000 );
		var end = new Date( Math.min( point.t + chartView.step, chartView.to ) * 1000 );
		var dateLabel = date.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
		var timeFormat = { hour: '2-digit', minute: '2-digit', hour12: false };
		var head = dateLabel + ' ' + date.toLocaleTimeString( [], timeFormat ) + '–' + end.toLocaleTimeString( [], timeFormat );
		var rows = '';
		TIP_ORDER.forEach( function( key ) {
			var value = point[ FIELD[ key ] ];
			var visible = 'rate' === key || state.on[ key ];
			var show = visible && null !== value && undefined !== value;
			chartView.dots[ key ].style.display = show ? '' : 'none';
			if ( show ) {
				var y = chartView.yOf[ key ]( value );
				chartView.dots[ key ].setAttribute( 'cx', px );
				chartView.dots[ key ].setAttribute( 'cy', y );
				rows += '<div class="row"><i style="background:' + COLORS[ key ] + '"></i>' + LABELS[ key ] + '<b>' + ( 'rate' === key ? ( value * 100 ).toFixed( 1 ) + '%' : fmtInt( value ) ) + '</b></div>';
			}
		} );
		EVENTS.forEach( function( key ) {
			var value = point[ FIELD[ key ] ];
			if ( state.on[ key ] && null !== value && undefined !== value && value > 0 ) {
				rows += '<div class="row"><i style="background:' + COLORS[ key ] + '"></i>' + LABELS[ key ] + '<b>' + fmtInt( value ) + '</b></div>';
			}
		} );
		tip.innerHTML = '<div class="d">' + head + '</div>' + rows;
		tip.hidden = false;
		var chartRect = svg.getBoundingClientRect(), wrapRect = wrap.getBoundingClientRect();
		var left = chartRect.left - wrapRect.left + px * ( chartRect.width / chartView.W ) + 16;
		if ( left + tip.offsetWidth > wrapRect.width - 4 ) {
			left = chartRect.left - wrapRect.left + px * ( chartRect.width / chartView.W ) - tip.offsetWidth - 16;
		}
		tip.style.left = Math.max( 4, left ) + 'px';
		tip.style.top = Math.max( 4, Math.min( event.clientY - wrapRect.top - tip.offsetHeight / 2, wrapRect.height - tip.offsetHeight - 4 ) ) + 'px';
	}
	function hidePoint() {
		if ( chartView ) {
			chartView.hover.style.display = 'none';
		}
		if ( tip ) {
			tip.hidden = true;
		}
	}

	svg.addEventListener( 'pointermove', function( event ) {
		if ( 'mouse' !== event.pointerType ) {
			return;
		}
		var index = pointIndex( event );
		showPoint( index, event );
		if ( null !== state.pinnedIndex ) {
			state.pinnedIndex = index;
		}
	} );
	svg.addEventListener( 'pointerleave', function() {
		if ( null === state.pinnedIndex ) {
			hidePoint();
		}
	} );
	svg.addEventListener( 'click', function( event ) {
		var lineHit = event.target.closest && event.target.closest( '[data-yac-line-hit]' );
		if ( ! lineHit ) {
			state.pinnedIndex = null;
			hidePoint();
			return;
		}
		state.pinnedIndex = pointIndex( event );
		showPoint( state.pinnedIndex, event );
	} );
	svg.addEventListener( 'keydown', function( event ) {
		if ( 'Escape' === event.key ) {
			state.pinnedIndex = null;
			hidePoint();
		}
	} );

	document.querySelectorAll( '.yac-ocache-metric[aria-pressed]' ).forEach( function( card ) {
		var key = card.getAttribute( 'data-yac-series' );
		card.addEventListener( 'click', function() {
			state.on[ key ] = ! state.on[ key ];
			card.classList.toggle( 'is-selected', state.on[ key ] );
			card.setAttribute( 'aria-pressed', state.on[ key ] ? 'true' : 'false' );
			draw();
		} );
	} );
	document.querySelectorAll( '[data-yac-range]' ).forEach( function( button ) {
		button.addEventListener( 'click', function() {
			var next = button.getAttribute( 'data-yac-range' );
			if ( next === state.view || ! data.ranges[ next ] ) {
				return;
			}
			state.view = next;
			document.querySelectorAll( '[data-yac-range]' ).forEach( function( item ) {
				var active = item === button;
				item.classList.toggle( 'is-active', active );
				item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
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
