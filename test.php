<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Weekly Task Stats</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #f9fafb;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
    }

    .chart-container {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      padding: 20px;
      width: 80%;
      max-width: 800px;
    }

    h2 {
      text-align: center;
      font-size: 20px;
      margin-bottom: 20px;
    }

    canvas {
      border-radius: 12px;
    }
  </style>
</head>
<body>
  <div class="chart-container">
    <h2>Weekly Task Stats</h2>
    <canvas id="scoreChart"></canvas>
  </div>

  <script>
    const days = ["Day 1","Day 2","Day 3","Day 4","Day 5","Day 6","Predicted"];

    // Генерація випадкових значень для Morning/Afternoon/Evening
    const randomValues = () => Array.from({length: 6}, () => Math.floor(Math.random() * 6));

    const morning = randomValues();
    const afternoon = randomValues();
    const evening = randomValues();

    // Прогноз: середнє + noise
    const avg = (arr) => arr.reduce((a, b) => a + b, 0) / arr.length;
    const predictedValue = Math.max(
      0,
      Math.min(
        15,
        Math.round(avg(morning) + avg(afternoon) + avg(evening) + (Math.random() * 3 - 1.5))
      )
    );

    const data = {
      labels: days,
      datasets: [
        {
          label: "Morning",
          data: [...morning, null], // Predicted не показуємо тут
          backgroundColor: "#F7F06D",
          borderRadius: 8,
          barPercentage: 0.4,
          categoryPercentage: 0.6,
        },
        {
          label: "Afternoon",
          data: [...afternoon, null],
          backgroundColor: "#4DAA57",
          borderRadius: 8,
          barPercentage: 0.4,
          categoryPercentage: 0.6,
        },
        {
          label: "Evening",
          data: [...evening, null],
          backgroundColor: "#222A68",
          borderRadius: 8,
          barPercentage: 0.4,
          categoryPercentage: 0.6,
        },
        {
          label: "Predicted",
          data: [null, null, null, null, null, null, predictedValue],
          backgroundColor: "rgba(128, 0, 255, 0.2)", // прозорий фіолетовий
          borderColor: "#8000FF",
          borderWidth: 3,
          borderRadius: 10,
          barPercentage: 0.5,
          categoryPercentage: 0.7,
        }
      ],
    };

    const options = {
      responsive: true,
      plugins: {
        legend: {
          position: "top",
          labels: { font: { size: 12 } }
        },
        tooltip: {
          backgroundColor: "rgba(0,0,0,0.7)",
          padding: 10,
          bodyFont: { size: 13 }
        }
      },
      scales: {
        x: {
          stacked: true,
          grid: { display: false }
        },
        y: {
          stacked: true,
          ticks: { stepSize: 1 },
          grid: { color: "#e5e7eb" }
        }
      }
    };

    new Chart(document.getElementById("scoreChart"), {
      type: "bar",
      data: data,
      options: options,
    });
  </script>
</body>
</html>
