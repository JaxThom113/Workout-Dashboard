<section class="about-content">
    <h1>About</h1>
    <p>
        Welcome to Jaxon's Workout Dashboard! I made this website to provide analytics and metrics 
        based on data I keep in workout logs in my Notion.
    </p>
    
    <h2>Features</h2>
    <ul>
        <li>View all your workout entries from Notion</li>
        <li>Automatic caching for faster load times</li>
        <li>Manual refresh to get the latest data</li>
    </ul>

    <h2>Explanation</h2>
    <p>
        I structure my workout logs in Notion as seen in the image below:
    </p>
    <img src="/assets/images/workout_dashboard_1.png" />
    <p>
        My parser for Notion recurses through this tree structure and updates the Training Log
        page accordingly. 
    </p>

    <h2>Documentation</h2>
    <p>
        This website was built with PHP, Apache, and MySQL using Notion API. Its database is cloud hosted
        with AWS for data persistence. If you would like to use this website as a dashboard for your own
        workout logs in Notion, feel free to make a fork of my repository!
    </p>
    <p>
        Here is the link to my Github repository:
    </p>
    <a href="https://github.com/JaxThom113/Workout-Dashboard">https://github.com/JaxThom113/Workout-Dashboard</a>

</section>
